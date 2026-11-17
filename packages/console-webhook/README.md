# Acta Console & Webhook

Two more ways in and out of an event-sourced application, for PHP 8.5+:

- **Incoming webhooks**: a PSR-15 handler that verifies signatures, handles each
  delivery once and translates it into commands.
- **Outgoing webhooks**: domain events turned into public integration events, queued,
  signed, sent over PSR-18, retried with growing delays and kept as dead letters when
  given up.
- **Console**: the operational commands of an application built on
  [`acta/event-sourcing`](../event-sourcing): migrations, projections, webhook delivery,
  contract linting, login.

Like an HTTP handler, a webhook receiver and a console command are primary adapters:
they translate an outside request into a command and hold no business rule.

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
- `acta/event-sourcing`, `symfony/console`, and the PSR interfaces for HTTP messages,
  factories, handlers and clients, clocks and logs
- For the PostgreSQL adapters and `es:migrate`: `ext-pdo_pgsql` and PostgreSQL 14 or later
- Optional: `acta/http` for `openapi:lint` and contract validation, `acta/oidc` for
  `auth:login`, `ext-pcntl` to stop long-running commands cleanly

## Installation

```bash
composer require acta/console-webhook
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
$registry->add('webhooks', fn() => $outbox->projection($projections));

$sender = new WebhookSender($queue, $subscriptions, $httpClient, $requestFactory, $streamFactory);
```

```bash
bin/console projection:run webhooks     # events -> deliveries
bin/console webhook:deliver --watch     # deliveries -> subscribers
```

Subscribers receive, signed with their own secret:

```json
{"id": "018f4e3a-…", "type": "account.credited", "timestamp": "2026-01-01T09:00:00.000+00:00",
 "data": {"accountId": "acc-1", "amount": "12000", "currency": "EUR"}}
```

### Console commands

| Command | Role |
| --- | --- |
| `es:migrate` | Creates the tables of the event store and of the other adapters |
| `projection:run`, `projection:rebuild`, `projection:status`, `projection:stop` | Runs and controls projections |
| `webhook:deliver`, `webhook:dead-letters`, `webhook:retry` | Sends outgoing webhooks and deals with failures |
| `openapi:lint` | Checks the OpenAPI contract (with `acta/http`) |
| `auth:login`, `auth:logout` | Device-flow login from the terminal (with `acta/oidc`) |

They are plain Symfony Console commands with constructor dependencies: register them in
any console application, `laminas-cli` included.

## Documentation

| Guide | What it covers |
| --- | --- |
| [Incoming webhooks](docs/incoming-webhooks.md) | Signature schemes, deduplication, translators, transactions, answers |
| [Outgoing webhooks](docs/outgoing-webhooks.md) | Integration events, subscriptions, the outbox, retries, dead letters, contract validation |
| [Console](docs/console.md) | Each command, registering projections and schemas, business commands |

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
