<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Postgres;

use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Postgres\PdoTransactionManager;
use Acta\EventSourcing\Postgres\PostgresEventStore;
use Acta\EventSourcing\Postgres\PostgresProjectionStore;
use Acta\EventSourcing\Postgres\PostgresSchema;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\SystemClock;
use Acta\Webhook\Incoming\PostgresDeliveryLog;
use Acta\Webhook\Incoming\WebhookReceiver;
use Acta\Webhook\Incoming\WebhookSource;
use Acta\Webhook\Outgoing\DeliveryReport;
use Acta\Webhook\Outgoing\InMemorySubscriptions;
use Acta\Webhook\Outgoing\PostgresDeliveryQueue;
use Acta\Webhook\Outgoing\Subscription;
use Acta\Webhook\Outgoing\WebhookOutbox;
use Acta\Webhook\Outgoing\WebhookSender;
use Acta\Webhook\Signature\StandardWebhooks;
use Acta\Webhook\Tests\Fixture\Banking\DepositMoney;
use Acta\Webhook\Tests\Fixture\Banking\FlagPayment;
use Acta\Webhook\Tests\Fixture\Banking\MoneyDeposited;
use Acta\Webhook\Tests\Fixture\Banking\PaymentProviderTranslator;
use Acta\Webhook\Tests\Fixture\Banking\PublicEvents;
use Acta\Webhook\Tests\Fixture\FakeSubscriber;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * A payment provider notifies the bank, the bank records a deposit, and its own
 * subscribers are notified in turn: both webhook adapters around an event store, on
 * PostgreSQL.
 */
#[Group('postgres')]
final class EndToEndTest extends TestCase
{
    use ConnectsToPostgres;

    private const array TABLES = ['e2e_events', 'e2e_projections', 'e2e_snapshots', 'e2e_received', 'e2e_deliveries'];

    private PDO $pdo;

    private PostgresEventStore $eventStore;

    private StandardWebhooks $provider;

    private WebhookReceiver $receiver;

    private WebhookOutbox $outbox;

    private ProjectionManager $projections;

    private PostgresDeliveryQueue $queue;

    private WebhookSender $sender;

    private FakeSubscriber $subscriber;

    /** Makes the handling of the next command fail, after its event was appended. */
    private bool $failNext = false;

    #[Override]
    protected function setUp(): void
    {
        $this->pdo = $pdo = self::connect();

        foreach (self::TABLES as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }

        $types = new MessageTypeMap(MoneyDeposited::class);
        $causation = new CausationMiddleware('webhook_id');
        $envelopes = new EnvelopeFactory($types, new SystemClock(), $causation);
        $transactions = new PdoTransactionManager($pdo);
        $schema = new PostgresSchema('e2e_events', 'e2e_projections', 'e2e_snapshots');
        $log = new PostgresDeliveryLog($pdo, 'e2e_received');
        $this->queue = new PostgresDeliveryQueue($pdo, 'e2e_deliveries');
        $this->eventStore = $eventStore = new PostgresEventStore($pdo, new MessageSerializer($types), 'e2e_events');

        // What the application runs at deployment, with `es:migrate` of acta/console.
        foreach ([...$schema->statements(), ...$log->statements(), ...$this->queue->statements()] as $statement) {
            $pdo->exec($statement);
        }

        $handlers = new HandlerMap()->on(DepositMoney::class, function (DepositMoney $command) use ($eventStore, $envelopes): void {
            $eventStore->append(
                StreamName::for('account', $command->accountId),
                ExpectedVersion::any(),
                $envelopes->wrap(new MoneyDeposited($command->accountId, $command->money)),
            );

            if ($this->failNext) {
                $this->failNext = false;

                throw new RuntimeException('The handler failed after appending its event.');
            }
        });

        $this->provider = new StandardWebhooks('provider-secret');
        $this->receiver = new WebhookReceiver(
            new DefaultCommandBus($handlers, $envelopes, $causation),
            [new WebhookSource('payments', $this->provider, new PaymentProviderTranslator())],
            new ResponseFactory(),
            new StreamFactory(),
            $log,
            $transactions,
        );

        $subscriptions = new InMemorySubscriptions(new Subscription('crm', 'https://crm.example.test/hooks', 'crm-secret'));
        $this->projections = new ProjectionManager($eventStore, new PostgresProjectionStore($pdo, 'e2e_projections'), $transactions, $envelopes);
        $this->outbox = new WebhookOutbox(new PublicEvents(), $subscriptions, $this->queue);
        $this->subscriber = new FakeSubscriber();
        $this->sender = new WebhookSender($this->queue, $subscriptions, $this->subscriber, new RequestFactory(), new StreamFactory());
    }

    #[Override]
    protected function tearDown(): void
    {
        // The connection does not exist when the test was skipped for lack of a server.
        if (!isset($this->pdo)) {
            return;
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        foreach (self::TABLES as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    public function testAPaymentNotificationTravelsThroughTheBankToItsSubscribers(): void
    {
        $response = $this->receiver->handle($this->payment('msg_1', 'pay_1', '12000'));

        self::assertSame(204, $response->getStatusCode());

        $events = [...$this->eventStore->read(Selector::all())];

        self::assertCount(1, $events);
        self::assertSame('msg_1', $events[0]->metadata->get('webhook_id'));

        $this->outbox->projection($this->projections)->run(keepRunning: false);

        self::assertEquals(new DeliveryReport(delivered: 1), $this->sender->deliverDue());

        $request = $this->subscriber->received[0];
        $body = json_decode((string) $request->getBody(), true);

        self::assertIsArray($body);
        self::assertSame('account.credited', $body['type']);
        self::assertSame(['accountId' => 'acc-1', 'amount' => '12000', 'currency' => 'EUR'], $body['data']);
        self::assertSame((string) $events[0]->envelope->id, $body['id']);

        // The subscriber can verify where it comes from.
        new StandardWebhooks('crm-secret')->verify($request, (string) $request->getBody());
    }

    public function testADeliveryRepeatedByTheProviderIsRecordedOnce(): void
    {
        $request = $this->payment('msg_1', 'pay_1', '12000');

        $this->receiver->handle($request);
        $this->receiver->handle($request);
        $this->receiver->handle($request);

        self::assertCount(1, [...$this->eventStore->read(Selector::all())]);
    }

    public function testAFailureLeavesNoTraceAndTheRetrySucceeds(): void
    {
        $request = $this->payment('msg_1', 'pay_1', '12000');
        $this->failNext = true;

        try {
            $this->receiver->handle($request);
            self::fail('The failure should be rethrown.');
        } catch (RuntimeException) {
        }

        // The event appended before the failure was rolled back with the record of the delivery.
        self::assertSame([], [...$this->eventStore->read(Selector::all())]);
        self::assertFalse($this->pdo->inTransaction());

        self::assertSame(204, $this->receiver->handle($request)->getStatusCode());
        self::assertCount(1, [...$this->eventStore->read(Selector::all())]);
    }

    public function testSubscribersAreNotifiedOnceHoweverOftenTheLogIsReplayed(): void
    {
        $this->receiver->handle($this->payment('msg_1', 'pay_1', '100'));
        $this->receiver->handle($this->payment('msg_2', 'pay_2', '200'));
        $projection = $this->outbox->projection($this->projections);

        $projection->run(keepRunning: false);
        self::assertEquals(new DeliveryReport(delivered: 2), $this->sender->deliverDue());

        $projection->reset();
        $projection->run(keepRunning: false);

        self::assertSame(0, $this->sender->deliverDue()->total());
        self::assertCount(2, $this->subscriber->received);
    }

    public function testAnOutageOfASubscriberEndsInTheDeadLetters(): void
    {
        $this->receiver->handle($this->payment('msg_1', 'pay_1', '100'));
        $this->outbox->projection($this->projections)->run(keepRunning: false);
        $this->subscriber->answers = [410];

        self::assertEquals(new DeliveryReport(dead: 1), $this->sender->deliverDue());

        $dead = $this->queue->deadLetters();

        self::assertCount(1, $dead);
        self::assertSame(410, $dead[0]->lastStatus);
    }

    public function testACommandNobodyHandlesIsNotAcknowledged(): void
    {
        $body = (string) json_encode(['type' => 'payment.disputed', 'data' => ['id' => 'pay_1']]);

        // No handler is registered for FlagPayment: the bus refuses, the provider will retry.
        $this->expectExceptionMessage(FlagPayment::class);

        $this->receiver->handle($this->signed('msg_1', $body));
    }

    private function payment(string $deliveryId, string $paymentId, string $amount): ServerRequestInterface
    {
        return $this->signed($deliveryId, (string) json_encode([
            'type' => 'payment.succeeded',
            'data' => ['id' => $paymentId, 'account' => 'acc-1', 'amount' => $amount, 'currency' => 'EUR'],
        ]));
    }

    private function signed(string $deliveryId, string $body): ServerRequestInterface
    {
        $request = new ServerRequest([], [], 'https://bank.example.test/webhooks/payments', 'POST', new StreamFactory()->createStream($body));

        foreach ($this->provider->sign($deliveryId, new SystemClock()->now(), $body) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
