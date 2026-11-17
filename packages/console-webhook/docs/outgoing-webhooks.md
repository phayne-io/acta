# Outgoing webhooks

```
event store ──projection──▶ WebhookOutbox ──▶ DeliveryQueue ──▶ WebhookSender ──HTTP──▶ subscribers
                 (mapper, subscriptions)                          (sign, retry, give up)
```

Sending is deliberately split in two. Deciding what to send is a projection: it follows
the event log with a checkpoint, so nothing is skipped. Sending is a worker on a queue:
it can fail, wait and try again without holding anything else up.

## Integration events

Domain events are internal. What subscribers receive is decided by an
`IntegrationEventMapper`, event by event:

```php
public function map(RecordedEvent $event): ?IntegrationEvent
{
    return match (true) {
        $event->message instanceof AccountOpened => new IntegrationEvent('account.opened', [/* … */]),
        default => null,   // private
    };
}
```

Everything is private unless the mapper says otherwise, and the public shape can stay
stable while domain events evolve. The body sent is:

```json
{"id": "<identifier of the domain event>", "type": "account.opened", "timestamp": "<RFC 3339>", "data": {}}
```

## Subscriptions

```php
$subscriptions = new InMemorySubscriptions(
    new Subscription('crm', 'https://crm.example.com/hooks', $secret),                       // everything
    new Subscription('ledger', 'https://ledger.example.com/in', $other, ['account.*']),      // by prefix
);

StandardWebhooks::generateSecret();   // 'whsec_…', to give to a new subscriber
```

URLs must be `https`. When subscribers manage their endpoints themselves, implement
`SubscriptionRepository` over your own storage, and validate the URLs they give you:
see the security notes.

A subscription only receives the events published after it was added.

## The outbox

```php
$outbox = new WebhookOutbox($mapper, $subscriptions, $queue, $validator, logger: $logger);
$projection = $outbox->projection($projections, 'webhooks');
```

Run it like any projection. For each public event, one delivery per interested
subscription is enqueued, with an identifier derived from the event and the
subscription. Enqueuing a known identifier does nothing, so replaying the log, after a
`projection:rebuild` for instance, sends nothing twice.

With `PostgresDeliveryQueue` on the connection of the projections, deliveries are
enqueued in the transaction of the checkpoint.

## Sending

```php
$sender = new WebhookSender($queue, $subscriptions, $httpClient, $requestFactory, $streamFactory, new RetryPolicy(), logger: $logger);

$report = $sender->deliverDue(limit: 100);   // DeliveryReport: delivered, retried, dead
```

Each delivery is POSTed as JSON and signed with the secret of its subscription
following Standard Webhooks. The `webhook-id` header is the same for every attempt:
deliveries are at-least-once, and subscribers deduplicate on it.

| Answer of the subscriber | Outcome |
| --- | --- |
| 2xx | Delivered |
| 410 Gone | Dead letter, at once |
| Anything else, timeout, network failure | Retried later, then dead letter |
| The subscription was removed | Dead letter, at once |

Use an HTTP client with short timeouts that does not follow redirects.

### Retries

The default `RetryPolicy` waits 5 seconds, 5 minutes, 30 minutes, 2 hours, 5 hours, then
10 hours twice: eight attempts over a little more than a day, with 10 % of jitter so
that deliveries failing together are not retried together.

```php
new RetryPolicy([10, 60, 600], jitter: 0.2);                              // your own schedule
RetryPolicy::exponential(maxAttempts: 6, firstDelay: 10, factor: 3.0);    // geometric
```

### Workers

`PostgresDeliveryQueue` hands a delivery to one worker only, with
`FOR UPDATE SKIP LOCKED`: run as many `webhook:deliver --watch` as you need. If a worker
dies during an attempt, the delivery becomes due again after the `reservation` of the
sender, 60 seconds by default; keep it longer than the timeout of the HTTP client.

`purgeDelivered($before)` deletes acknowledged deliveries; dead letters are kept.

### Dead letters

```bash
bin/console webhook:dead-letters
bin/console webhook:retry msg_5d193340800ec5d699bbe4c3450351d1
```

A dead letter keeps its body, the last status and the reason. Once the endpoint is
fixed, `webhook:retry` makes it due again with a fresh attempt count.

## Validating against the contract

Outgoing webhooks are part of the public API. Describe them in the `webhooks` section of
the OpenAPI 3.1 contract, one webhook per event type, and they are validated before
being queued, with the engine `acta/http` validates requests with:

```yaml
webhooks:
  account.opened:
    post:
      operationId: notifyAccountOpened
      requestBody:
        content:
          application/json:
            schema: { $ref: '#/components/schemas/AccountOpenedNotification' }
```

```php
new WebhookOutbox($mapper, $subscriptions, $queue, new ContractPayloadValidator($contract));
```

A body that does not match, or an event type the contract does not describe, is not
sent: its deliveries go straight to the dead letters with the defects as reason, and an
error is logged. Fix the mapper or the contract, then `webhook:retry`.

## Your own queue

Implement `DeliveryQueue` and extend `Acta\Webhook\Testing\DeliveryQueueContract` to
check it: ordering, reservations, retries, dead letters, idempotent enqueuing.
