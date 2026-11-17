# Incoming webhooks

`WebhookReceiver` is a PSR-15 request handler. Route `POST /webhooks/{source}` to it; the
name of the sender is read from the `source` request attribute, else from the last
segment of the path, or from wherever the `sourceOf` closure says.

## Signature schemes

A webhook is an unauthenticated request from the internet until its signature is
verified, on the raw body, before anything is decoded.

```php
// Standard Webhooks: webhook-id, webhook-timestamp, webhook-signature
new StandardWebhooks($secret, tolerance: 300);

// An HMAC of the body in a header (GitHub and many others)
new HmacSignature('X-Hub-Signature-256', $secret, idHeader: 'X-GitHub-Delivery');

// The same with a signed timestamp: the signature covers "{timestamp}.{body}"
new HmacSignature('X-Signature', $secret, 'sha256', 'v1=', HmacEncoding::Hex, 'X-Delivery-Id', 'X-Timestamp');
```

- **Timestamps** are checked against a tolerance, five minutes by default, so that a
  webhook captured on the wire cannot be replayed later. A scheme without timestamp
  only has deduplication against replays: prefer one with.
- **Rotation**: pass several secrets. A webhook is accepted when any of them verifies
  it; add the new secret, switch the sender, remove the old one.
- **Anything else**, such as a scheme packing timestamp and signatures in one header,
  is an implementation of `SignatureScheme`, about twenty lines.

`StandardWebhooks` was checked against the example of its specification, and
`HmacSignature` against the example of the GitHub documentation.

## Handling once

Senders deliver at least once: a timeout on their side and the same webhook comes
again. The `DeliveryLog` remembers what was handled, by sender and delivery identifier;
a repeated delivery is acknowledged without being translated again. When the scheme
carries no identifier, the hash of the body is used.

| Adapter | Use |
| --- | --- |
| `InMemoryDeliveryLog` | Tests, single-process servers |
| `PostgresDeliveryLog` | Production; `statements()` creates its table, `purge($now)` empties it |

Records are kept for `retention` seconds, a week by default: longer than the retry
period of your senders.

### Exactly once

Give the receiver a `TransactionManager`, and a `PostgresDeliveryLog` on the connection
your commands write through:

```php
new WebhookReceiver($commands, $sources, $responses, $streams, new PostgresDeliveryLog($pdo), new PdoTransactionManager($pdo));
```

The record of the delivery and the events its commands append are then one
transaction. If handling fails, both are rolled back and the retry of the sender starts
clean; if it succeeds, both are there. Without transaction manager the guarantee is
at-least-once: make your command handlers idempotent.

## Translators

A translator is the anti-corruption layer for one sender:

```php
public function translate(IncomingWebhook $webhook): iterable
{
    $webhook->source;    // 'payments'
    $webhook->id;        // identifier of the delivery
    $webhook->payload;   // decoded JSON
    $webhook->body;      // raw body
    $webhook->header('X-GitHub-Event');
    $webhook->sentAt;    // when the scheme carries a timestamp

    return [new DepositMoney(/* … */)];
}
```

- Return no command for webhooks you have no use for: they are acknowledged.
- Throw `UnprocessableWebhook` for a webhook that is authentic but makes no sense. The
  sender is answered 422 and the delivery is not translated again.
- Let any other exception through for a failure a retry may fix.

Commands are dispatched with the metadata `channel: webhook`, `webhook_source` and
`webhook_id`. With `CausationMiddleware` propagating `webhook_id`, every event recorded
because of a webhook names its delivery.

## Answers

| Outcome | Status |
| --- | --- |
| Handled, already handled, or of no interest | 204 |
| Unknown sender | 404 |
| Not a POST | 405 |
| Signature missing, wrong or too old | 401 |
| Body above `maxBodySize` (1 MiB by default) | 413 |
| Body that is not a JSON object or array | 400 |
| Refused by the translator | 422 |
| Failure while handling | The exception is rethrown |

Refusals carry a small problem document (RFC 9457). A rethrown exception must end as a
5xx, which `ProblemDetailsMiddleware` of `acta/http` does: that is what makes the
sender retry.

## Logging

Refused signatures and unprocessable webhooks are logged at `warning`, failures at
`error` with the exception, handled and repeated deliveries at `info`.
