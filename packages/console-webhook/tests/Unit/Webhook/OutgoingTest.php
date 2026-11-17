<?php

declare(strict_types=1);

namespace Acta\ConsoleWebhook\Tests\Unit\Webhook;

use Acta\ConsoleWebhook\Tests\Fixture\Banking\AccountOpened;
use Acta\ConsoleWebhook\Tests\Fixture\Banking\AuditTrailChecked;
use Acta\ConsoleWebhook\Tests\Fixture\Banking\MoneyDeposited;
use Acta\ConsoleWebhook\Tests\Fixture\Banking\PublicEvents;
use Acta\ConsoleWebhook\Tests\Fixture\FakeSubscriber;
use Acta\ConsoleWebhook\Tests\Fixture\FrozenClock;
use Acta\ConsoleWebhook\Tests\Fixture\RecordingLogger;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\Http\OpenApi\ContractLoader;
use Acta\Webhook\Bridge\ContractPayloadValidator;
use Acta\Webhook\Exception\ConfigurationError;
use Acta\Webhook\Outgoing\DeliveryReport;
use Acta\Webhook\Outgoing\DeliveryStatus;
use Acta\Webhook\Outgoing\InMemoryDeliveryQueue;
use Acta\Webhook\Outgoing\InMemorySubscriptions;
use Acta\Webhook\Outgoing\IntegrationEvent;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use Acta\Webhook\Outgoing\OutgoingDelivery;
use Acta\Webhook\Outgoing\RetryPolicy;
use Acta\Webhook\Outgoing\Subscription;
use Acta\Webhook\Outgoing\WebhookOutbox;
use Acta\Webhook\Outgoing\WebhookSender;
use Acta\Webhook\Signature\StandardWebhooks;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use Money\Money;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutgoingTest extends TestCase
{
    private FrozenClock $clock;

    private InMemoryEventStore $eventStore;

    private EnvelopeFactory $envelopes;

    private ProjectionManager $projections;

    private InMemorySubscriptions $subscriptions;

    private InMemoryDeliveryQueue $queue;

    private FakeSubscriber $http;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->eventStore = new InMemoryEventStore();
        $this->envelopes = new EnvelopeFactory(new MessageTypeMap(), $this->clock);
        $this->projections = new ProjectionManager($this->eventStore, new InMemoryProjectionStore(), $this->eventStore, $this->envelopes);
        $this->subscriptions = new InMemorySubscriptions(
            new Subscription('crm', 'https://crm.example.test/hooks', 'crm-secret'),
            new Subscription('ledger', 'https://ledger.example.test/in', 'ledger-secret', ['account.credited']),
        );
        $this->queue = new InMemoryDeliveryQueue();
        $this->http = new FakeSubscriber();
        $this->logger = new RecordingLogger();
    }

    public function testPublicEventsAreQueuedForTheSubscriptionsThatWantThem(): void
    {
        $this->record(new AccountOpened('acc-1', 'Ada'), new AuditTrailChecked('acc-1'), new MoneyDeposited('acc-1', Money::EUR(12000)));

        $this->outbox()->projection($this->projections)->run(keepRunning: false);

        $deliveries = $this->queue->all();

        self::assertSame(
            [['crm', 'account.opened'], ['crm', 'account.credited'], ['ledger', 'account.credited']],
            array_map(static fn(OutgoingDelivery $delivery): array => [$delivery->subscriptionId, $delivery->eventType], $deliveries),
        );
        self::assertSame(DeliveryStatus::Pending, $deliveries[0]->status);
        self::assertSame(0, $deliveries[0]->attempts);
        self::assertEquals($this->clock->now(), $deliveries[0]->nextAttemptAt);
        self::assertMatchesRegularExpression('/^msg_[0-9a-f]{32}$/', $deliveries[0]->id);

        $body = json_decode($deliveries[1]->body, true);

        self::assertIsArray($body);
        self::assertSame(['id', 'type', 'timestamp', 'data'], array_keys($body));
        self::assertSame('account.credited', $body['type']);
        self::assertSame('2026-01-01T00:00:00.000+00:00', $body['timestamp']);
        self::assertSame(['accountId' => 'acc-1', 'amount' => '12000', 'currency' => 'EUR'], $body['data']);
        // Both subscribers receive the same event, in two deliveries of their own.
        self::assertSame($deliveries[1]->body, $deliveries[2]->body);
        self::assertNotSame($deliveries[1]->id, $deliveries[2]->id);
    }

    public function testReplayingTheLogQueuesNothingTwice(): void
    {
        $this->record(new AccountOpened('acc-1', 'Ada'));
        $projection = $this->outbox()->projection($this->projections);

        $projection->run(keepRunning: false);
        $projection->run(keepRunning: false);

        self::assertCount(1, $this->queue->all());

        $this->sender()->deliverDue();
        $projection->reset();
        $projection->run(keepRunning: false);

        // The delivery that was acknowledged is still the only one, and still acknowledged.
        self::assertCount(1, $this->queue->all());
        self::assertSame(DeliveryStatus::Delivered, $this->queue->all()[0]->status);
        self::assertCount(1, $this->http->received);
    }

    public function testASubscriptionAddedLaterOnlyReceivesNewEvents(): void
    {
        $this->record(new AccountOpened('acc-1', 'Ada'));
        $projection = $this->outbox()->projection($this->projections);
        $projection->run(keepRunning: false);

        $this->subscriptions->add(new Subscription('audit', 'https://audit.example.test/', 'audit-secret', ['account.*']));
        $this->record(new AccountOpened('acc-2', 'Grace'));
        $projection->run(keepRunning: false);

        self::assertSame(['crm', 'crm', 'audit'], array_column($this->queue->all(), 'subscriptionId'));
    }

    public function testAnEventWithoutDataIsSentAsAnEmptyObject(): void
    {
        $mapper = new class implements IntegrationEventMapper {
            #[Override]
            public function map(\Acta\EventSourcing\EventStore\RecordedEvent $event): IntegrationEvent
            {
                return new IntegrationEvent('system.pinged', []);
            }
        };
        $this->record(new AuditTrailChecked('acc-1'));

        new WebhookOutbox($mapper, $this->subscriptions, $this->queue, clock: $this->clock)->projection($this->projections)->run(keepRunning: false);

        self::assertStringEndsWith('"data":{}}', $this->queue->all()[0]->body);
    }

    public function testADeliveryIsSignedAndAcknowledged(): void
    {
        $this->record(new AccountOpened('acc-1', 'Ada'));
        $this->outbox()->projection($this->projections)->run(keepRunning: false);
        $this->clock->advance('+2 seconds');

        $report = $this->sender()->deliverDue();

        self::assertEquals(new DeliveryReport(delivered: 1), $report);
        self::assertSame(1, $report->total());

        $request = $this->http->received[0];
        $delivery = $this->queue->all()[0];

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://crm.example.test/hooks', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('Acta-Webhooks/1', $request->getHeaderLine('User-Agent'));
        self::assertSame($delivery->id, $request->getHeaderLine('webhook-id'));
        self::assertSame($delivery->body, (string) $request->getBody());

        // The subscriber verifies it with its secret, and nobody else's.
        $verified = new StandardWebhooks('crm-secret', clock: $this->clock)->verify($request, (string) $request->getBody());

        self::assertSame($delivery->id, $verified->deliveryId);
        self::assertEquals($this->clock->now(), $verified->signedAt);
        self::assertSame(DeliveryStatus::Delivered, $delivery->status);
        self::assertSame(204, $delivery->lastStatus);
        self::assertSame(['info'], $this->logger->levels());

        // Nothing is due any more.
        self::assertSame(0, $this->sender()->deliverDue()->total());
    }

    public function testAFailedDeliveryIsRetriedWithGrowingDelaysThenGivenUp(): void
    {
        $this->record(new AccountOpened('acc-1', 'Ada'));
        $this->outbox()->projection($this->projections)->run(keepRunning: false);
        $sender = $this->sender(new RetryPolicy([5, 300, 1800], 0.0));
        $this->http->answers = [503, null, 500, 404, 204];
        $id = $this->queue->all()[0]->id;
        $waits = [];

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $report = $sender->deliverDue();
            $delivery = $this->queue->all()[0];
            $waits[] = $delivery->nextAttemptAt->getTimestamp() - $this->clock->now()->getTimestamp();

            self::assertSame($attempt, $delivery->attempts);
            self::assertEquals($attempt < 4 ? new DeliveryReport(retried: 1) : new DeliveryReport(dead: 1), $report);

            // Too early: nothing is attempted.
            self::assertSame(0, $sender->deliverDue()->total());
            $this->clock->advance('+1 hour');
        }

        self::assertSame([5, 300, 1800], array_slice($waits, 0, 3));
        self::assertSame(DeliveryStatus::Dead, $this->queue->all()[0]->status);
        self::assertSame(404, $this->queue->all()[0]->lastStatus);
        self::assertSame('The subscriber answered with status 404.', $this->queue->all()[0]->lastError);
        self::assertSame(['warning', 'warning', 'warning', 'error'], $this->logger->levels());
        self::assertStringContainsString('could not be reached: Connection timed out', (string) $this->logger->records[1]['context']['error']);

        // Every attempt carried the same identifier, so that the subscriber can deduplicate.
        self::assertSame([$id, $id, $id, $id], array_map(static fn($request): string => $request->getHeaderLine('webhook-id'), $this->http->received));

        // Once the endpoint is fixed, the dead letter is sent again by hand.
        self::assertSame([$id], array_column($this->queue->deadLetters(), 'id'));
        self::assertTrue($this->queue->requeue($id, $this->clock->now()));
        self::assertEquals(new DeliveryReport(delivered: 1), $sender->deliverDue());
    }

    public function testAGoneEndpointOrARemovedSubscriptionEndsADeliveryAtOnce(): void
    {
        $this->record(new MoneyDeposited('acc-1', Money::EUR(100)));
        $this->outbox()->projection($this->projections)->run(keepRunning: false);
        $this->subscriptions->remove('ledger');
        $this->http->answers = [410];

        $report = $this->sender()->deliverDue();

        self::assertEquals(new DeliveryReport(dead: 2), $report);
        self::assertSame(['https://crm.example.test/hooks'], $this->http->urls());
        self::assertSame(
            ['crm' => 'The subscriber answered with status 410.', 'ledger' => 'The subscription no longer exists.'],
            array_column($this->queue->all(), 'lastError', 'subscriptionId'),
        );
        self::assertSame(['error', 'notice'], $this->logger->levels());
    }

    public function testABatchIsLimitedAndOneFailureDoesNotBlockTheOthers(): void
    {
        $this->record(new AccountOpened('acc-1', 'Ada'), new AccountOpened('acc-2', 'Grace'), new AccountOpened('acc-3', 'Edsger'));
        $this->outbox()->projection($this->projections)->run(keepRunning: false);
        $this->http->answers = [500];
        $sender = $this->sender();

        self::assertEquals(new DeliveryReport(delivered: 1, retried: 1), $sender->deliverDue(2));
        self::assertEquals(new DeliveryReport(delivered: 1), $sender->deliverDue(2));
    }

    public function testABodyThatBreaksTheContractIsNotSent(): void
    {
        $validator = new ContractPayloadValidator(ContractLoader::load(__DIR__ . '/../../Fixture/webhooks.yaml'));
        $outbox = new WebhookOutbox(new PublicEvents(), $this->subscriptions, $this->queue, $validator, $this->clock, $this->logger);
        // Valid, then an empty owner, then a currency the contract does not list.
        $this->record(new AccountOpened('acc-1', 'Ada'), new AccountOpened('acc-2', ''), new MoneyDeposited('acc-1', Money::GBP(100)));

        $outbox->projection($this->projections)->run(keepRunning: false);

        self::assertSame(
            [DeliveryStatus::Pending, DeliveryStatus::Dead, DeliveryStatus::Dead, DeliveryStatus::Dead],
            array_column($this->queue->all(), 'status'),
        );
        self::assertSame(
            'The body does not match its description: /data/owner: Minimum string length is 1, found 0',
            $this->queue->all()[1]->lastError,
        );
        self::assertStringContainsString('/data/currency: The data should match one item from enum', (string) $this->queue->all()[2]->lastError);
        self::assertSame(['error', 'error'], $this->logger->levels());

        // Only the valid one leaves the application.
        self::assertEquals(new DeliveryReport(delivered: 1), $this->sender()->deliverDue());
        self::assertCount(3, $this->queue->deadLetters());
    }

    public function testTheContractMustDescribeEveryEventType(): void
    {
        $validator = new ContractPayloadValidator(ContractLoader::load(__DIR__ . '/../../Fixture/webhooks.yaml'));

        self::assertSame(['The contract describes no "account.frozen" webhook.'], $validator->validate('account.frozen', []));
        // A webhook described without body schema accepts anything.
        self::assertSame([], $validator->validate('account.closed', ['anything' => true]));
        self::assertSame(
            ['/: The required properties (id, timestamp, data) are missing'],
            $validator->validate('account.opened', ['type' => 'account.opened']),
        );
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('wantedEventTypes')]
    public function testASubscriptionChoosesItsEventTypes(array $patterns, string $type, bool $wanted): void
    {
        self::assertSame($wanted, new Subscription('s', 'https://example.test/', 'secret', $patterns)->wants($type));
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function wantedEventTypes(): iterable
    {
        yield 'everything' => [['*'], 'account.opened', true];
        yield 'exact' => [['account.opened'], 'account.opened', true];
        yield 'another type' => [['account.opened'], 'account.closed', false];
        yield 'prefix' => [['account.*'], 'account.opened', true];
        yield 'prefix of a longer name' => [['account.*'], 'accounting.closed', false];
        yield 'prefix is not the type itself' => [['account.*'], 'account', false];
        yield 'several' => [['card.*', 'account.closed'], 'account.closed', true];
        yield 'none' => [[], 'account.opened', false];
    }

    public function testASubscriptionNeedsAnHttpsUrl(): void
    {
        new Subscription('local', 'http://localhost:8080/hooks', 'secret', allowInsecure: true);

        foreach (['http://crm.example.test/hooks', 'ftp://crm.example.test', 'crm.example.test/hooks', ''] as $url) {
            try {
                new Subscription('s', $url, 'secret');
                self::fail($url . ' should be refused.');
            } catch (ConfigurationError $error) {
                self::assertStringContainsString('is not an acceptable webhook URL', $error->getMessage());
            }
        }
    }

    public function testSubscriptionsAreFoundByIdentifierAndByEventType(): void
    {
        self::assertSame('https://crm.example.test/hooks', $this->subscriptions->find('crm')?->url);
        self::assertNull($this->subscriptions->find('unknown'));
        self::assertSame(['crm'], array_column([...$this->subscriptions->wanting('account.opened')], 'id'));
        self::assertSame(['crm', 'ledger'], array_column([...$this->subscriptions->wanting('account.credited')], 'id'));
    }

    public function testTheDefaultScheduleRidesOutADayOfOutage(): void
    {
        $policy = new RetryPolicy(jitter: 0.0);

        self::assertSame(8, $policy->maxAttempts());
        self::assertSame(
            [5, 300, 1800, 7200, 18000, 36000, 36000, null],
            array_map($policy->delayAfter(...), range(1, 8)),
        );
        self::assertNull($policy->delayAfter(0));
        self::assertNull($policy->delayAfter(99));
    }

    public function testDelaysAreSpreadByTheJitter(): void
    {
        $lowest = new RetryPolicy([100], 0.2, static fn(): float => 0.0);
        $middle = new RetryPolicy([100], 0.2, static fn(): float => 0.5);
        $highest = new RetryPolicy([100], 0.2, static fn(): float => 1.0);

        self::assertSame([80, 100, 120], [$lowest->delayAfter(1), $middle->delayAfter(1), $highest->delayAfter(1)]);

        $random = new RetryPolicy([1000]);

        for ($i = 0; $i < 20; ++$i) {
            self::assertEqualsWithDelta(1000, $random->delayAfter(1), 100);
        }
    }

    public function testAnExponentialScheduleIsBounded(): void
    {
        $policy = RetryPolicy::exponential(maxAttempts: 6, firstDelay: 10, factor: 3.0, maxDelay: 200, jitter: 0.0);

        self::assertSame(6, $policy->maxAttempts());
        self::assertSame([10, 30, 90, 200, 200, null], array_map($policy->delayAfter(...), range(1, 6)));
        self::assertSame(1, RetryPolicy::exponential(maxAttempts: 1)->maxAttempts());
    }

    public function testAPolicyRefusesNonsense(): void
    {
        foreach ([[[-1], 0.1], [[5], 1.5], [[5], -0.1]] as [$delays, $jitter]) {
            try {
                new RetryPolicy($delays, $jitter);
                self::fail('The policy should be refused.');
            } catch (ConfigurationError $error) {
                self::assertStringContainsString('cannot be negative', $error->getMessage());
            }
        }
    }

    private function record(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->eventStore->append(
                StreamName::for('account', $event->accountId ?? 'x'),
                ExpectedVersion::any(),
                $this->envelopes->wrap($event),
            );
        }
    }

    private function outbox(): WebhookOutbox
    {
        return new WebhookOutbox(new PublicEvents(), $this->subscriptions, $this->queue, clock: $this->clock, logger: $this->logger);
    }

    private function sender(RetryPolicy $retries = new RetryPolicy(jitter: 0.0)): WebhookSender
    {
        return new WebhookSender($this->queue, $this->subscriptions, $this->http, new RequestFactory(), new StreamFactory(), $retries, $this->clock, $this->logger);
    }
}
