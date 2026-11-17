# With acta/event-sourcing

`Acta\Http\EventSourcing` holds four small classes connecting this package to
[`acta/event-sourcing`](../../event-sourcing). They are optional: nothing else in the
package depends on it.

```bash
composer require acta/event-sourcing
```

A handler in this setup translates and delegates. It is a primary adapter in the
hexagonal sense: no business rule, and the application is only reached through a bus.

```php
#[Operation('depositMoney')]
final readonly class DepositMoneyHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = Input::from($request);
        $id = AccountId::fromString($input->path['accountId']);

        IfMatch::assert($request, $this->view->version($id));

        $money = new Money($input->body['amount'], new Currency($input->body['currency']));
        $command = $this->messages->create(DepositMoney::class, $input, ['money' => $money]);

        $this->commands->dispatch($command, $this->metadata->fromRequest($request));

        return $this->view->respond($id);
    }
}
```

## Messages from input

`MessageFactory` builds a command or a query from the validated input, matching fields
to the constructor parameters of the message by name:

```php
final readonly class OpenAccount implements Command
{
    public function __construct(public AccountId $accountId, public string $owner, public string $currency = 'EUR') {}
}

$command = $messages->create(OpenAccount::class, $input, ['accountId' => AccountId::generate()]);
```

- Values come from the body, then the query parameters, then the path parameters, then
  the overrides; the later ones win. A client therefore cannot replace an identifier of
  the path by a field of the body.
- The conventions are those of the payload serializer: identifiers from strings, enums
  from their values, dates from RFC 3339 strings, nested objects from JSON objects.
- Overrides may be built objects. Use them when the wire format and the value object
  differ, as for `Money` above, or for what does not come from the client at all.

The contract has already validated the input, so a field the message needs and the
input lacks means the contract and the message disagree: it is reported as a server
error, to be caught by a test.

## Metadata

`MetadataFactory` builds the metadata of the messages a request gives rise to:

```php
$metadata = new MetadataFactory([
    'actor' => fn(ServerRequestInterface $request) => $request->getAttribute(Principal::class)?->subject,
    'tenant' => fn(ServerRequestInterface $request) => $request->getAttribute('tenant'),
]);

$commands->dispatch($command, $metadata->fromRequest($request));
```

The trace identifier of the request becomes the correlation identifier, and the
`traceparent` is kept. With `CausationMiddleware` on the buses, every event recorded
while handling the request carries them, along with the keys you chose to propagate:
an event can be traced back to the HTTP call that caused it.

## Versions, ETag and If-Match

The version of an aggregate makes a natural entity tag:

```php
$responder->json($view, 200, ['ETag' => IfMatch::etag($account->version)]);   // ETag: "7"

IfMatch::assert($request, $account->version);   // 412 unless If-Match is absent, "*" or "7"
```

A client that read version 7 and sends `If-Match: "7"` is refused once somebody else
changed the account, instead of silently building on stale data. To require the header,
declare it as a required parameter of the operation.

`IfMatch::expectedVersion($request)` returns the `ExpectedVersion` if you prefer to
pass it down to the application layer.

## Errors

`EventSourcingExceptionMapper` gives a status to the exceptions of the write side:

| Exception | Status |
| --- | --- |
| `AggregateNotFound` | 404 |
| `ConcurrencyConflict` | 409 |
| `AccessDenied` | 403 |

Internal messages, which name streams and versions, are replaced by neutral details.
Register it before your own mappers:

```php
new ProblemDetailsMiddleware($responder, [new EventSourcingExceptionMapper(), new BusinessRuleMapper()]);
```
