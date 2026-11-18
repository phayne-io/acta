# MyBank

A small bank as a hexagonal, event-sourced application in PHP 8.5 and later: a
contract-first API, a console and webhooks in front of a domain that knows none of them.

It wires the four Acta libraries together on Mezzio, and comes with generators that
scaffold further contexts the same way as its `Banking` one.

| | |
| --- | --- |
| **Domain** | Event-sourced aggregates, commands, queries, projections — [`acta/event-sourcing`](../event-sourcing) |
| **API** | An OpenAPI 3.1 contract drives routing, security, validation and RFC 9457 errors, on PSR-7/15/17 — [`acta/http`](../http) |
| **Security** | OAuth 2.0 / OpenID Connect: Bearer JWT verified against the JWKS of your identity provider — [`acta/oidc`](../oidc) |
| **Console and webhooks** | Operational commands, signed incoming webhooks, outgoing webhooks with outbox and retries — [`acta/console-webhook`](../console-webhook) |
| **Persistence** | PostgreSQL in production, in memory in tests, behind the same ports |

## Requirements

PHP 8.5 with `pdo_pgsql` and `bcmath`, Composer, PostgreSQL 14 or later. Or Docker,
which brings them all.

## Start

You need Docker and [mkcert](https://github.com/FiloSottile/mkcert).

```sh
make hosts     # once: api.mybank.io and auth.mybank.io in /etc/hosts (asks for sudo)
make up        # the API over HTTPS, its workers, PostgreSQL, Valkey and Keycloak
```

The API answers on <https://api.mybank.io>; its contract is served at `/openapi.json`.

```sh
TOKEN=$(make -s token)

curl -i -X POST https://api.mybank.io/accounts \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"owner": "Ada", "currency": "EUR"}'
# 201 Created — Location: /accounts/0197…

curl -X POST https://api.mybank.io/accounts/0197…/deposits \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"amount": "12000", "currency": "EUR"}'

curl -H "Authorization: Bearer $TOKEN" https://api.mybank.io/accounts/0197…
# {"id":"0197…","owner":"Ada","balance":{"amount":"12000","currency":"EUR"},"version":2}
```

### A project of your own

```sh
composer create-project acta/skeleton my-shop
```

asks what the application is called and under which domain its local stack answers,
and puts the answers wherever a name is needed: `.env`, the contract, `composer.json`,
this documentation. Change them later in `.env`.

Without Docker, see [Getting started](docs/getting-started.md).

## What is in the box

```
bin/console               console entry point
public/index.php          HTTP entry point
openapi/openapi.yaml      the contract of the API: the source of truth
config/                   what is plugged in, the pipeline, the settings
src/
  Banking/                a business context — the worked example
    Domain/                 the model and its rules: Account, its events, its exceptions
    Application/            the use cases: commands, queries and the ports they need
    Infrastructure/
      Primary/              what drives the application: HTTP, console, incoming webhooks
      Secondary/            what the application drives: event store, read model, outgoing webhooks
    ConfigProvider.php      the table of contents of the context
  Platform/               the wiring of the libraries — no business in there
  Shared/                 the little that contexts share
templates/make/           what the generators write, yours to adapt
tests/                    one directory per context, plus architecture and end-to-end tests
```

Dependencies point inwards: the domain depends on nothing but the aggregate and message
types and its value objects; the application on the domain; the adapters on both. This
is not a convention: `composer architecture` fails the build when a class breaks it.

## Add your own context

```sh
bin/console make:context Billing
bin/console make:aggregate Billing Invoice
bin/console make:command Billing IssueInvoice --event InvoiceIssued
bin/console make:query Billing GetInvoice
bin/console make:webhook Billing stripe
```

Each generator writes documented classes in the right layer, registers them, completes
the OpenAPI contract, and leaves a test that stays red until you have written the
business rule. See [Adding a context](docs/adding-a-context.md). Once your own context
stands, delete `src/Banking`, `tests/Banking`, its line in `config/config.php` and its
paths in the contract.

## Check

```sh
composer check          # everything below, as the continuous integration does
composer cs             # PER coding style
composer stan           # PHPStan, level max
composer architecture   # the dependency rules between layers (Deptrac)
composer lint:contract  # the OpenAPI contract
composer test           # PHPUnit; the PostgreSQL tests are skipped without a server

composer mutation       # mutation testing of the domain and the application (needs Xdebug or PCOV)
```

## Documentation

- [Getting started](docs/getting-started.md) — with and without Docker, the first requests
- [The local stack](docs/local-stack.md) — HTTPS with mkcert, Keycloak, Valkey: how it fits together
- [Architecture](docs/architecture.md) — the layers, the path of a request, consistency
- [Adding a context](docs/adding-a-context.md) — the generators, step by step
- [Configuration](docs/configuration.md) — environment variables, what a context declares, swapping an adapter
- [Operations](docs/operations.md) — migrations, projection workers, webhooks, deployment
- [Testing](docs/testing.md) — the four kinds of tests and what each one is for
- [Decisions](docs/adr) — why things are the way they are

## License

MIT. See [LICENSE](LICENSE).
