<?php

declare(strict_types=1);

/*
 * Webhooks in, webhooks out, around an event store, in one file and in memory.
 *
 *     php examples/quickstart.php
 *
 * A payment provider notifies the bank; the bank records a deposit; the subscribers of
 * the bank are notified in turn. Amounts are modelled with moneyphp/money, a
 * development dependency of this package.
 */

namespace Acta\Example\Webhook;

use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Message\Command;
use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\Webhook\Exception\UnprocessableWebhook;
use Acta\Webhook\Incoming\IncomingWebhook;
use Acta\Webhook\Incoming\WebhookReceiver;
use Acta\Webhook\Incoming\WebhookSource;
use Acta\Webhook\Incoming\WebhookTranslator;
use Acta\Webhook\Outgoing\InMemoryDeliveryQueue;
use Acta\Webhook\Outgoing\InMemorySubscriptions;
use Acta\Webhook\Outgoing\IntegrationEvent;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use Acta\Webhook\Outgoing\Subscription;
use Acta\Webhook\Outgoing\WebhookOutbox;
use Acta\Webhook\Outgoing\WebhookSender;
use Acta\Webhook\Signature\StandardWebhooks;
use DateTimeImmutable;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Money\Currency;
use Money\Money;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

// --- Domain and application ------------------------------------------------------------

final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(public string $accountId, public Money $money, public string $reference) {}
}

final readonly class DepositMoney implements Command
{
    public function __construct(public string $accountId, public Money $money, public string $reference) {}
}

// --- Incoming: what the payment provider says, in the words of the bank ----------------

final readonly class PaymentProviderTranslator implements WebhookTranslator
{
    public function translate(IncomingWebhook $webhook): iterable
    {
        $payment = $webhook->payload['data'] ?? [];

        return match ($webhook->payload['type'] ?? null) {
            'payment.succeeded' => [new DepositMoney(
                $payment['account'],
                new Money($payment['amount'], new Currency($payment['currency'])),
                $payment['id'],
            )],
            'ping' => [],
            default => throw new UnprocessableWebhook('Unknown event type.'),
        };
    }
}

// --- Outgoing: what the bank says to the outside, in its public vocabulary -------------

final readonly class PublicEvents implements IntegrationEventMapper
{
    public function map(RecordedEvent $event): ?IntegrationEvent
    {
        $message = $event->message;

        return $message instanceof MoneyDeposited
            ? new IntegrationEvent('account.credited', [
                'accountId' => $message->accountId,
                'amount' => $message->money->getAmount(),
                'currency' => $message->money->getCurrency()->getCode(),
            ])
            : null;
    }
}

/** Stands in for the endpoints of the subscribers: the ledger is down for its first two calls. */
final class Subscribers implements ClientInterface
{
    private int $ledgerCalls = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $host = $request->getUri()->getHost();
        $status = $host === 'ledger.example.test' && ++$this->ledgerCalls <= 2 ? 503 : 204;
        printf("    -> POST %-24s %d  %s\n", $host, $status, $request->getHeaderLine('webhook-id'));

        return new Response('php://memory', $status);
    }
}

// --- Wiring ----------------------------------------------------------------------------

$clock = new class implements \Psr\Clock\ClockInterface {
    public DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-01-01T09:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
};

$eventStore = new InMemoryEventStore();
$envelopes = new EnvelopeFactory(clock: $clock);
$commands = new DefaultCommandBus(
    new HandlerMap()->on(DepositMoney::class, static fn(DepositMoney $command) => $eventStore->append(
        StreamName::for('account', $command->accountId),
        ExpectedVersion::any(),
        $envelopes->wrap(new MoneyDeposited($command->accountId, $command->money, $command->reference)),
    )),
    $envelopes,
);

$providerSecret = StandardWebhooks::generateSecret();
$receiver = new WebhookReceiver(
    $commands,
    [new WebhookSource('payments', new StandardWebhooks($providerSecret, clock: $clock), new PaymentProviderTranslator())],
    new ResponseFactory(),
    new StreamFactory(),
    transactions: $eventStore,
    clock: $clock,
);

$subscriptions = new InMemorySubscriptions(
    new Subscription('crm', 'https://crm.example.test/hooks', StandardWebhooks::generateSecret()),
    new Subscription('ledger', 'https://ledger.example.test/in', StandardWebhooks::generateSecret(), ['account.*']),
);
$queue = new InMemoryDeliveryQueue();
$projections = new ProjectionManager($eventStore, new InMemoryProjectionStore(), $eventStore, $envelopes);
$outbox = new WebhookOutbox(new PublicEvents(), $subscriptions, $queue, clock: $clock)->projection($projections);
$sender = new WebhookSender($queue, $subscriptions, new Subscribers(), new RequestFactory(), new StreamFactory(), clock: $clock);

// --- Use -------------------------------------------------------------------------------

// The payment provider signs and sends a webhook, as it would over HTTP.
$notify = static function (string $deliveryId, array $payload, ?string $secret = null) use ($receiver, $providerSecret, $clock): void {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $request = new ServerRequest([], [], 'https://bank.example.test/webhooks/payments', 'POST', new StreamFactory()->createStream($body));

    foreach (new StandardWebhooks($secret ?? $providerSecret)->sign($deliveryId, $clock->now(), $body) as $name => $value) {
        $request = $request->withHeader($name, $value);
    }

    $response = $receiver->handle($request);
    printf("  %-44s %d %s\n", $deliveryId . ' ' . ($payload['type'] ?? ''), $response->getStatusCode(), $response->getBody());
};
$payment = ['type' => 'payment.succeeded', 'data' => ['id' => 'pay_1', 'account' => 'acc-1', 'amount' => '12000', 'currency' => 'EUR']];

echo "Incoming webhooks\n";
$notify('msg_1', $payment);
$notify('msg_1', $payment);                                  // delivered twice by the provider: handled once
$notify('msg_2', ['type' => 'ping']);                        // of no interest: acknowledged
$notify('msg_3', ['type' => 'refund.created']);              // not understood
$notify('msg_4', $payment, 'whsec_' . base64_encode('x'));   // forged

printf("\nEvents recorded: %d\n", count([...$eventStore->load(StreamName::for('account', 'acc-1'))]));

echo "\nOutgoing webhooks\n";
$outbox->run(keepRunning: false);

foreach (['09:00', '09:01', '09:10'] as $time) {
    $clock->now = new DateTimeImmutable("2026-01-01T{$time}:00+00:00");
    // What `webhook:deliver` of acta/console does, from a worker.
    $report = $sender->deliverDue();
    printf("  %s  %d delivered, %d to retry, %d given up\n", $time, $report->delivered, $report->retried, $report->dead);
}

$dead = $queue->deadLetters();
printf("\nDead letters: %d\n", count($dead));

foreach ($dead as $delivery) {
    printf("  %s for %s after %d attempts: %s\n", $delivery->eventType, $delivery->subscriptionId, $delivery->attempts, $delivery->lastError ?? '');
}
