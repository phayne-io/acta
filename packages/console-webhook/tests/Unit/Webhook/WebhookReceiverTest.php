<?php

declare(strict_types=1);

namespace Acta\ConsoleWebhook\Tests\Unit\Webhook;

use Acta\ConsoleWebhook\Tests\Fixture\Banking\DepositMoney;
use Acta\ConsoleWebhook\Tests\Fixture\Banking\FlagPayment;
use Acta\ConsoleWebhook\Tests\Fixture\Banking\PaymentProviderTranslator;
use Acta\ConsoleWebhook\Tests\Fixture\FrozenClock;
use Acta\ConsoleWebhook\Tests\Fixture\RecordingCommandBus;
use Acta\ConsoleWebhook\Tests\Fixture\RecordingLogger;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\Webhook\Exception\ConfigurationError;
use Acta\Webhook\Incoming\IncomingWebhook;
use Acta\Webhook\Incoming\InMemoryDeliveryLog;
use Acta\Webhook\Incoming\WebhookReceiver;
use Acta\Webhook\Incoming\WebhookSource;
use Acta\Webhook\Incoming\WebhookTranslator;
use Acta\Webhook\Signature\HmacSignature;
use Acta\Webhook\Signature\StandardWebhooks;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Money\Money;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class WebhookReceiverTest extends TestCase
{
    private FrozenClock $clock;

    private RecordingCommandBus $commands;

    private RecordingLogger $logger;

    private StandardWebhooks $payments;

    private WebhookReceiver $receiver;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->commands = new RecordingCommandBus();
        $this->logger = new RecordingLogger();
        $this->payments = new StandardWebhooks('payments-secret', clock: $this->clock);
        $this->receiver = $this->receiver();
    }

    public function testAWebhookBecomesACommand(): void
    {
        $response = $this->receiver->handle($this->payment('msg_1', 'payment.succeeded', ['id' => 'pay_1', 'account' => 'acc-1', 'amount' => '12000', 'currency' => 'EUR']));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertEquals([new DepositMoney('acc-1', Money::EUR(12000), 'pay_1')], $this->commands->commands());

        // Whatever the command causes can be traced back to the delivery.
        self::assertSame(
            ['channel' => 'webhook', 'webhook_source' => 'payments', 'webhook_id' => 'msg_1'],
            $this->commands->dispatched[0][1]?->values,
        );
        self::assertSame(['info'], $this->logger->levels());
        self::assertSame(1, $this->logger->records[0]['context']['commands']);
    }

    public function testAWebhookMayGiveRiseToSeveralCommandsOrNone(): void
    {
        self::assertSame(204, $this->receiver->handle($this->payment('msg_1', 'payment.disputed', ['id' => 'pay_1']))->getStatusCode());
        self::assertSame(204, $this->receiver->handle($this->payment('msg_2', 'ping', []))->getStatusCode());

        self::assertEquals([new FlagPayment('pay_1', 'disputed'), new FlagPayment('pay_1', 'frozen')], $this->commands->commands());
    }

    public function testADeliveryIsHandledOnce(): void
    {
        $request = $this->payment('msg_1', 'payment.succeeded', ['id' => 'pay_1', 'account' => 'acc-1', 'amount' => '100', 'currency' => 'EUR']);

        self::assertSame(204, $this->receiver->handle($request)->getStatusCode());
        // The sender did not get the first answer and delivers again.
        self::assertSame(204, $this->receiver->handle($request)->getStatusCode());

        self::assertCount(1, $this->commands->dispatched);
        self::assertSame('Webhook {id} of {source} was already handled.', $this->logger->records[1]['message']);

        // Another delivery of the same content is another payment notification.
        $this->receiver->handle($this->payment('msg_2', 'payment.succeeded', ['id' => 'pay_1', 'account' => 'acc-1', 'amount' => '100', 'currency' => 'EUR']));

        self::assertCount(2, $this->commands->dispatched);
    }

    public function testAHandledDeliveryIsForgottenAfterTheRetentionPeriod(): void
    {
        $receiver = $this->receiver(retention: 3600);
        $body = (string) json_encode(['type' => 'payment.disputed', 'data' => ['id' => 'pay_1']]);
        $send = fn(): ResponseInterface => $receiver->handle($this->signed('payments', $this->payments, 'msg_1', $body));

        $send();
        $this->clock->advance('+3599 seconds');
        // Signed again by the sender for its retry: same identifier, fresh timestamp.
        $send();

        self::assertCount(2, $this->commands->dispatched);

        $this->clock->advance('+3601 seconds');
        $send();

        self::assertCount(4, $this->commands->dispatched);
    }

    public function testAFailureLetsTheSenderRetry(): void
    {
        $attempts = 0;
        $this->commands->handler = static function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new RuntimeException('The database is down.');
            }
        };
        $request = $this->payment('msg_1', 'payment.succeeded', ['id' => 'pay_1', 'account' => 'acc-1', 'amount' => '100', 'currency' => 'EUR']);

        try {
            $this->receiver->handle($request);
            self::fail('The failure should be rethrown, so that the sender gets a 5xx.');
        } catch (RuntimeException $exception) {
            self::assertSame('The database is down.', $exception->getMessage());
        }

        self::assertSame(['error'], $this->logger->levels());
        self::assertSame($exception, $this->logger->records[0]['context']['exception']);

        // The delivery was not recorded as handled: the retry goes through.
        self::assertSame(204, $this->receiver->handle($request)->getStatusCode());
        self::assertCount(1, $this->commands->dispatched);
    }

    public function testCommandsOfAWebhookAreDispatchedInOneTransaction(): void
    {
        $transactions = new InMemoryEventStore();
        $inTransaction = [];
        $this->commands->handler = static function () use ($transactions, &$inTransaction): void {
            $inTransaction[] = $transactions->inTransaction();
        };

        $this->receiver(transactions: $transactions)->handle($this->payment('msg_1', 'payment.disputed', ['id' => 'pay_1']));

        self::assertSame([true, true], $inTransaction);
        self::assertFalse($transactions->inTransaction());
    }

    public function testAnUnsignedOrForgedWebhookIsRefused(): void
    {
        $body = (string) json_encode(['type' => 'payment.succeeded', 'data' => ['id' => 'pay_1', 'account' => 'acc-1', 'amount' => '1000000', 'currency' => 'EUR']]);
        $forged = new StandardWebhooks('guessed-secret', clock: $this->clock);

        foreach ([
            [self::request('payments', $body), 'The webhook-id, webhook-timestamp and webhook-signature headers are required.'],
            [$this->signed('payments', $forged, 'msg_1', $body), 'The signature of the webhook is not valid.'],
            // Signed for another body: the amount was changed on the way.
            [$this->signed('payments', $this->payments, 'msg_1', $body, str_replace('1000000', '9000000', $body)), 'The signature of the webhook is not valid.'],
        ] as [$request, $detail]) {
            $response = $this->receiver->handle($request);

            self::assertSame(401, $response->getStatusCode());
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
            self::assertSame(
                ['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'detail' => $detail],
                json_decode((string) $response->getBody(), true),
            );
        }

        self::assertSame([], $this->commands->dispatched);
        self::assertSame(['warning', 'warning', 'warning'], $this->logger->levels());
    }

    public function testEachSourceHasItsOwnSchemeAndTranslator(): void
    {
        $github = new HmacSignature('X-Hub-Signature-256', 'github-secret', idHeader: 'X-GitHub-Delivery');
        $translator = new class implements WebhookTranslator {
            /** @var list<IncomingWebhook> */
            public array $seen = [];

            #[Override]
            public function translate(IncomingWebhook $webhook): iterable
            {
                $this->seen[] = $webhook;

                return [];
            }
        };
        $receiver = $this->receiver(new WebhookSource('github', $github, $translator));
        $body = '{"action":"opened"}';

        self::assertSame(204, $receiver->handle($this->signed('github', $github, 'dlv-1', $body))->getStatusCode());
        // A webhook of one source is not accepted on the URL of another.
        self::assertSame(401, $receiver->handle($this->signed('payments', $github, 'dlv-2', $body))->getStatusCode());

        $seen = $translator->seen;

        self::assertCount(1, $seen);
        self::assertSame('github', $seen[0]->source);
        self::assertSame('dlv-1', $seen[0]->id);
        self::assertSame(['action' => 'opened'], $seen[0]->payload);
        self::assertSame($body, $seen[0]->body);
        self::assertNull($seen[0]->sentAt);
        self::assertSame('dlv-1', $seen[0]->header('X-GitHub-Delivery'));
        self::assertNull($seen[0]->header('X-Missing'));
    }

    public function testWithoutIdentifierTheSameBytesAreTheSameDelivery(): void
    {
        $scheme = new HmacSignature('X-Signature', 'secret');
        $receiver = $this->receiver(new WebhookSource('legacy', $scheme, new PaymentProviderTranslator()));
        $body = (string) json_encode(['type' => 'payment.disputed', 'data' => ['id' => 'pay_1']]);

        $receiver->handle($this->signed('legacy', $scheme, 'unused', $body));
        $receiver->handle($this->signed('legacy', $scheme, 'unused', $body));
        $receiver->handle($this->signed('legacy', $scheme, 'unused', str_replace('pay_1', 'pay_2', $body)));

        self::assertCount(4, $this->commands->dispatched);
    }

    public function testRequestsThatAreNotWebhooksAreRefused(): void
    {
        $signed = fn(string $body): ServerRequestInterface => $this->signed('payments', $this->payments, 'msg_' . md5($body), $body);
        $cases = [
            [self::request('unknown', '{}'), 404, 'No webhook is expected here.'],
            [self::request('payments', '', 'GET'), 405, 'Webhooks are sent with POST.'],
            [$signed('not json'), 400, 'The body of a webhook must be a JSON object or array.'],
            [$signed('"a string"'), 400, 'The body of a webhook must be a JSON object or array.'],
            [$signed('{"type":"refund.created"}'), 422, 'Unknown event type "refund.created".'],
            [$signed('{"type":"payment.succeeded","data":{"id":"pay_1"}}'), 422, 'The payment has no "account".'],
        ];

        foreach ($cases as [$request, $status, $detail]) {
            $response = $this->receiver->handle($request);

            self::assertSame($status, $response->getStatusCode(), $detail);
            self::assertSame($detail, json_decode((string) $response->getBody(), true)['detail'] ?? null);
        }

        self::assertSame('POST', $this->receiver->handle(self::request('payments', '', 'GET'))->getHeaderLine('Allow'));
        self::assertSame([], $this->commands->dispatched);

        // A webhook the translator refused is not translated again when delivered again.
        $refused = $signed('{"type":"refund.created"}');

        self::assertSame(204, $this->receiver->handle($refused)->getStatusCode());
    }

    public function testOversizedBodiesAreRefusedBeforeBeingVerified(): void
    {
        $receiver = $this->receiver(maxBodySize: 64);
        $body = (string) json_encode(['type' => 'ping', 'padding' => str_repeat('x', 100)]);

        $response = $receiver->handle($this->signed('payments', $this->payments, 'msg_1', $body));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('Webhooks are limited to 64 bytes.', json_decode((string) $response->getBody(), true)['detail'] ?? null);
        self::assertSame(204, $receiver->handle($this->signed('payments', $this->payments, 'msg_2', '{"type":"ping"}'))->getStatusCode());
    }

    public function testTheSourceIsReadFromARouteAttributeFirst(): void
    {
        $body = '{"type":"ping"}';
        $request = $this->signed('ignored-segment', $this->payments, 'msg_1', $body)->withAttribute('source', 'payments');

        self::assertSame(204, $this->receiver->handle($request)->getStatusCode());

        // Or from wherever the application says.
        $receiver = new WebhookReceiver(
            $this->commands,
            [new WebhookSource('payments', $this->payments, new PaymentProviderTranslator())],
            new ResponseFactory(),
            new StreamFactory(),
            clock: $this->clock,
            sourceOf: static fn(ServerRequestInterface $request): string => $request->getHeaderLine('X-Source'),
        );

        self::assertSame(204, $receiver->handle($this->signed('anything', $this->payments, 'msg_2', $body)->withHeader('X-Source', 'payments'))->getStatusCode());
        self::assertSame(204, $this->receiver->handle($this->signed('payments/', $this->payments, 'msg_3', $body))->getStatusCode());
    }

    public function testTwoSourcesCannotShareAName(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('Two webhook sources are named "payments".');

        $this->receiver(new WebhookSource('payments', $this->payments, new PaymentProviderTranslator()));
    }

    private function receiver(
        ?WebhookSource $extra = null,
        ?InMemoryEventStore $transactions = null,
        int $retention = 604_800,
        int $maxBodySize = 1_048_576,
    ): WebhookReceiver {
        return new WebhookReceiver(
            $this->commands,
            [new WebhookSource('payments', $this->payments, new PaymentProviderTranslator()), ...($extra === null ? [] : [$extra])],
            new ResponseFactory(),
            new StreamFactory(),
            new InMemoryDeliveryLog(),
            $transactions,
            $retention,
            $maxBodySize,
            $this->clock,
            $this->logger,
        );
    }

    /**
     * @param array<string, string> $data
     */
    private function payment(string $id, string $type, array $data): ServerRequestInterface
    {
        return $this->signed('payments', $this->payments, $id, (string) json_encode(['type' => $type, 'data' => $data]));
    }

    private function signed(string $source, StandardWebhooks|HmacSignature $scheme, string $id, string $signedBody, ?string $sentBody = null): ServerRequestInterface
    {
        $request = self::request($source, $sentBody ?? $signedBody);

        foreach ($scheme->sign($id, $this->clock->now(), $signedBody) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private static function request(string $source, string $body, string $method = 'POST'): ServerRequestInterface
    {
        return new ServerRequest([], [], 'https://bank.example.test/webhooks/' . $source, $method, new StreamFactory()->createStream($body), ['Content-Type' => 'application/json']);
    }
}
