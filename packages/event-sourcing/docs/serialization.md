# Serialization and upcasting

Events are stored as a name, a schema version, a JSON payload and JSON metadata.
`MessageSerializer` converts envelopes to that form and back. It is built from three
collaborators:

```php
$serializer = new MessageSerializer(
    new MessageTypeMap(AccountOpened::class, MoneyDeposited::class), // names
    new ReflectionPayloadSerializer(),                               // payloads
    new UpcasterChain(new AccountOpenedV1ToV2()),                    // old schemas
);
```

## Names

By default a message is named after its class. Give events a public name so that classes
can be renamed and moved freely:

```php
#[MessageName('banking.account-opened', version: 2)]
final readonly class AccountOpened implements DomainEvent { … }
```

Classes with a `#[MessageName]` must be registered in the `MessageTypeMap`, otherwise
their name cannot be resolved when reading. A stored name that is an existing class name
is always accepted, which covers events written before the attribute was added.

## Payloads

`ReflectionPayloadSerializer` describes a message by the parameters of its constructor,
read from the properties of the same name, which is what constructor promotion gives.

| PHP value | Stored as |
| --- | --- |
| scalar, `null`, array | itself |
| backed enum | its value |
| pure enum | its case name |
| `DateTimeInterface` | RFC 3339 string with microseconds |
| `Stringable` with a static `fromString()` | string, which fits identifiers such as `AggregateId` |
| object implementing `SerializablePayload` | what `toPayload()` returns |
| any other object | nested map, with the same rules |

Arrays of objects need a hint to be rebuilt:

```php
public function __construct(
    public OrderId $orderId,
    #[ListOf(OrderLine::class)] public array $lines,
) {}
```

Value objects of other libraries follow the same rules, as long as their constructor
parameters have properties of the same name, private or not. A `Money\Money` from
[moneyphp/money](https://github.com/moneyphp/money) needs no adapter:

```php
#[MessageName('banking.money-deposited')]
final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(public AccountId $accountId, public Money $money) {}
}

// new MoneyDeposited($id, Money::EUR(12_000)) is stored as:
// {"accountId": "018f…", "money": {"amount": "12000", "currency": {"code": "EUR"}}}
```

When a message is rebuilt, a value that already is an object of the expected class is
kept as it is. An adapter can therefore mix decoded input with value objects it built
itself.

A constructor parameter missing from a stored payload takes its default value, or `null`
when nullable. Adding an optional field to an event therefore needs no upcaster.

Union types holding objects, computed fields and payloads that differ from the
constructor are out of reach of these conventions. Implement `SerializablePayload` on
the message or on the value object concerned:

```php
final readonly class Price implements SerializablePayload
{
    public function toPayload(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency->value];
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload['amount'], Currency::from($payload['currency']));
    }
}
```

To delegate to another library altogether, implement `PayloadSerializer`.

## Upcasting

When the shape of an event changes in a way a default value cannot absorb, raise the
`version` of its `#[MessageName]` and add an upcaster for the events already stored.
Upcasters work on `SerializedMessage`, before the class is instantiated, so the domain
only ever sees the current shape.

```php
final class AccountOpenedV1ToV2 implements Upcaster
{
    public function upcast(SerializedMessage $message): iterable
    {
        if ($message->name !== 'banking.account-opened' || $message->version !== 1) {
            return [$message];
        }

        return [$message->withPayload([...$message->payload, 'currency' => 'EUR'], version: 2)];
    }
}
```

An upcaster can also rename an event with `withName()`, drop it by returning nothing, or
split it by returning several messages. Events born from one stored event share its
stream version and its position.

`UpcasterChain` runs upcasters in order, each receiving the output of the previous one.

Stored events are never rewritten: upcasting happens on every read.

## Testing serialization without a database

Give a serializer to the in-memory event store and every read goes through
deserialization and upcasting, as with PostgreSQL:

```php
$eventStore = new InMemoryEventStore(new MessageSerializer($types, upcaster: $upcasters));
```
