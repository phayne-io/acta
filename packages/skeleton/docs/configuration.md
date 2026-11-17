# Configuration

## Environment variables

Read once, in `config/autoload/global.php`; `.env.dist` lists them with development
values.

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_DEBUG` | off | Adds exception details to problem documents; recompiles the contract when it changes |
| `APP_PERSISTENCE` | `postgres` | `memory` keeps all state in the process, for tests |
| `DATABASE_DSN`, `DATABASE_USER`, `DATABASE_PASSWORD` | local `app` database | The PostgreSQL connection |
| `PROJECTIONS_AFTER_COMMAND` | empty | Comma-separated projections brought up to date after each command, where no worker runs. Empty in production |
| `HTTP_BASE_PATH` | empty | Prefix of the API when it is served under a path, `/api` for instance |
| `HTTP_RESPONSE_VALIDATION` | `log` | `fail` turns a response that breaks the contract into a 500; `log` reports it and lets it through |
| `OIDC_ISSUER` | — | Issuer of the identity provider; its metadata and keys are discovered from it |
| `OIDC_AUDIENCE` | — | Identifier of this API, expected in the `aud` claim of access tokens |
| `OIDC_ALLOW_INSECURE` | off | Accepts an `http://` issuer. For a local provider only |
| `OIDC_CLIENT_ID` | — | Public client `bin/console auth:login` logs in with (device flow) |
| `WEBHOOK_<SOURCE>_SECRET` | — | Secret shared with a sender of incoming webhooks |
| `LOG_STREAM`, `LOG_LEVEL` | `php://stderr`, `info` | Where the JSON log lines go, and from which PSR-3 level |

`config/autoload/local.php`, which is not committed, overrides any setting for one
machine. Start from `local.php.dist`.

Webhook subscribers are listed under `acta.webhooks.subscriptions`:

```php
'subscriptions' => [
    ['id' => 'crm', 'url' => 'https://crm.example/hooks', 'secret' => 'whsec_…', 'events' => ['account.*']],
],
```

## What a context declares

Under the `acta` key of its `ConfigProvider`:

| Key | Entries |
| --- | --- |
| `events` | Its domain events, so that they can be stored and read back |
| `command_handlers`, `query_handlers` | Classes with a `#[Handles]` method |
| `operations` | Request handlers with an `#[Operation('operationId')]` attribute |
| `exception_mappers` | `ExceptionMapper` services: how its exceptions translate to HTTP |
| `projections` | `ProjectionDefinition` services, keyed by projection name |
| `schemas` | `Schema` services: the tables `es:migrate` creates |
| `webhook_sources` | `WebhookSource` services: who may send it webhooks |
| `integration_events` | `IntegrationEventMapper` services: what it publishes to subscribers |
| `console.commands` | Console commands, keyed by name |

Every entry but the events is a service identifier, usually a class name. Under
`dependencies`, a context maps its ports to its adapters (`aliases`) and declares the
few services that need a factory.

## Swapping an adapter

Everything the platform builds is a service named after an interface, in
`src/Platform/ConfigProvider.php`. Override one in a `ConfigProvider` listed after it,
or in `config/autoload`:

```php
'dependencies' => [
    'factories' => [
        // Monolog instead of the built-in JSON logger
        Psr\Log\LoggerInterface::class => App\Ops\MonologFactory::class,
        // Subscriptions managed at run time instead of read from the configuration
        Acta\Webhook\Outgoing\SubscriptionRepository::class => App\Ops\DatabaseSubscriptionsFactory::class,
    ],
],
```

Common candidates: `LoggerInterface`, `Psr\Http\Client\ClientInterface` (Guzzle by
default), `Psr\SimpleCache\CacheInterface` (where the keys of the identity provider are
cached; the filesystem by default, a shared cache when you run several instances),
`SubscriptionRepository`, `ClockInterface`.

To authenticate a second security scheme of the contract, an API key for instance,
implement `Acta\Http\Security\Authenticator` and add it under
`acta.http.authenticators`, keyed by the name of the scheme.

## The identity provider

The API is a resource server: it verifies the signature of each Bearer JWT against the
keys the provider publishes (JWKS), then its issuer, audience, expiry and the scopes
the operation requires in the contract. Keys are cached and fetched again when a token
names one that is not known, which is how key rotation is followed.

Handlers read the caller with `Acta\Oidc\Token\Principal::from($request)`; its subject
is recorded as the `actor` of every event the request causes.

Scopes are read from the `scope` claim, roles from the usual places; a provider with
claims of its own needs a `ClaimsMapper` — see the documentation of `acta/oidc`.
