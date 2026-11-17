# Testing

```sh
composer test        # everything; PostgreSQL tests are skipped when no server answers
composer test:unit   # without the PostgreSQL group
make -C ../../docker test   # in Docker, against the database of the local stack
```

Four kinds of tests, from the fastest and most numerous to the fewest.

## Domain: scenarios

`tests/<Context>/Domain` — the rules, with no infrastructure at all. A scenario says
what happened, what is asked and what must follow:

```php
AggregateScenario::for(Account::class)
    ->given(new AccountOpened($id, 'Ada', new Currency('EUR')), new MoneyDeposited($id, Money::EUR(500)))
    ->when(fn(Account $account) => $account->withdraw(Money::EUR(501)))
    ->thenThrows(InsufficientFunds::class);
```

This is where most of the tests of a context belong.

`composer mutation` tells whether they are enough. Infection changes the domain and the
application one operator at a time — a `<=` into a `<`, a call removed — and runs the
tests of the contexts against each change; a change no test notices is a rule no test
checks. The build fails under the thresholds of `infection.json5`.

## Application and adapters: in memory

`tests/<Context>/Application`, `tests/<Context>/Infrastructure` — handlers against a
repository that keeps its events in memory, projections against an in-memory read
model, translators against sample payloads. No container, no database:

```php
$accounts = new EventSourcedAccountRepository(new InMemoryEventStore());
new OpenAccountHandler($accounts)(new OpenAccount($id, 'Ada'));
```

`PublicEventsTest` checks what the context publishes against the `webhooks` section of
the contract, so that subscribers never receive something undocumented.

## Architecture

`tests/Architecture` — the contract is valid; every operation has a handler and every
handler an operation; every operation states the scopes it requires; contexts do not
reach into one another. Together with `composer architecture`, these keep the shape of
the application from eroding.

## End to end

`tests/Functional` — the whole application, wired as in production:
`TestApplication::boot()` builds the real container and replaces only the outside
world. The identity provider is `Acta\Oidc\Testing\FakeIdentityProvider`, which signs
real tokens; webhook subscribers are a recording HTTP client.

```php
$app = TestApplication::boot();

$response = $app->request('POST', '/accounts', ['owner' => 'Ada'], $app->token('banking:write'));
$app->paymentWebhook('msg_1', ['type' => 'payment.succeeded', 'data' => […]]);
$app->console('webhook:deliver');
$app->subscriber->notifications();
```

The scenarios of `ApplicationTestCase` run twice: in memory (`InMemoryApplicationTest`,
always) and against PostgreSQL (`PostgresApplicationTest`, group `postgres`). The same
assertions passing on both is what shows that the adapters are interchangeable. Add the
scenarios of your contexts there, or in a test case of their own built on
`TestApplication`.

Responses are validated against the contract in tests (`response_validation: fail`), so
an end-to-end test that passes is also a proof that the response is the documented one.

## Static checks

Not tests, but part of `composer check`: PER coding style, PHPStan at its strictest
level on `src` (level 8 on `tests`), the Deptrac rules and the contract linter.
