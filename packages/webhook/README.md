# Acta Webhook

Webhooks in and out of an application built on
[`acta/event-sourcing`](../event-sourcing), for PHP 8.5+:

- **Incoming webhooks**: a PSR-15 handler that verifies signatures, handles each
  delivery once and translates it into commands.
- **Outgoing webhooks**: domain events turned into public integration events, queued,
  signed, sent over PSR-18, retried with growing delays and kept as dead letters when
  given up.

The receiver is a primary adapter, like an HTTP handler: it translates an outside request
into a command and holds no business rule. The outbox and the sender are secondary
adapters: the application drives them, through the events it records.

- **Signatures** following [Standard Webhooks](https://www.standardwebhooks.com/), or
  any "HMAC in a header" scheme such as GitHub's, with secret rotation.
- **Exactly-once handling** of incoming deliveries on PostgreSQL: the record of a
  delivery commits with the effects of its commands.
- **A transactional outbox** fed by a projection: no event is skipped, none is sent
  twice because the log was replayed.
- **Concurrent workers** on the delivery queue, with `FOR UPDATE SKIP LOCKED`.
- **Validation** of outgoing webhooks against the `webhooks` section of the OpenAPI
  contract, with `acta/http`.
- **Contract test suites** for your own delivery log and delivery queue adapters.

## Requirements

- PHP 8.5 or later
- `acta/event-sourcing`, and the PSR interfaces for HTTP messages, factories, handlers
  and clients, clocks and logs
- For the PostgreSQL adapters: `ext-pdo_pgsql` and PostgreSQL 14 or later
- Optional: [`acta/console`](../console) for the `webhook:*` commands and `es:migrate`,
  `acta/http` to validate outgoing webhooks against the OpenAPI contract

## Installation

```bash
composer require acta/webhook
```

## Quick start

The snippets below follow [`examples/quickstart.php`](examples/quickstart.php), which
runs both directions in memory.

### Receiving webhooks

Say what the webhooks of a sender mean, in commands of your application:

```php
final readonly class PaymentProviderTranslator implements WebhookTranslator
{
    public function translate(IncomingWebhook $webhook): iterable
    {
        $payment = $webhook->payload['data'];

        return match ($webhook->payload['type']) {
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
```

Then route `POST /webhooks/{source}` to the receiver:

```php
$receiver = new WebhookReceiver(
    $commandBus,
    [
        new WebhookSource('payments', new StandardWebhooks($secret), new PaymentProviderTranslator()),
        new WebhookSource('github', new HmacSignature('X-Hub-Signature-256', $githubSecret, idHeader: 'X-GitHub-Delivery'), $githubTranslator),
    ],
    $responseFactory,
    $streamFactory,
    new PostgresDeliveryLog($pdo),
    $transactions,
);
```

### Sending webhooks

Say which domain events are public, and in which shape:

```php
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
```

A projection fills the queue, a worker empties it:

```php
$outbox = new WebhookOutbox(new PublicEvents(), $subscriptions, new PostgresDeliveryQueue($pdo));
$outbox->projection($projections)->run();          // events -> deliveries

$sender = new WebhookSender($queue, $subscriptions, $httpClient, $requestFactory, $streamFactory);
$report = $sender->deliverDue();                   // deliveries -> subscribers
```

With [`acta/console`](../console), both are commands to run as workers, and the tables
are created by `es:migrate`:

```bash
bin/console projection:run webhooks
bin/console webhook:deliver --watch
```

Subscribers receive, signed with their own secret:

```json
{"id": "018f4e3a-…", "type": "account.credited", "timestamp": "2026-01-01T09:00:00.000+00:00",
 "data": {"accountId": "acc-1", "amount": "12000", "currency": "EUR"}}
```

## Documentation

| Guide | What it covers |
| --- | --- |
| [Incoming webhooks](docs/incoming-webhooks.md) | Signature schemes, deduplication, translators, transactions, answers |
| [Outgoing webhooks](docs/outgoing-webhooks.md) | Integration events, subscriptions, the outbox, retries, dead letters, contract validation |

Every public class and method is documented in the source as well.

## Development

```bash
docker compose up -d      # PostgreSQL for the integration tests
composer install
composer check            # coding standard, static analysis, tests
composer test:unit        # tests that do not need PostgreSQL
```

Inside the Acta monorepo, the other Acta packages are installed from their sibling
directories. The PostgreSQL tests read their connection from the `ACTA_TEST_POSTGRES_*`
environment variables, see [`phpunit.xml.dist`](phpunit.xml.dist), and are skipped when
the server cannot be reached.

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

Released under the [MIT License](LICENSE).
