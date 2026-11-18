# Acta Console

The operational commands of an application built on
[`acta/event-sourcing`](../event-sourcing), for PHP 8.5+: create the tables, run and
control the projections and, with the libraries they belong to, deliver webhooks, lint
the OpenAPI contract and log in.

A console command is a primary adapter, like an HTTP handler: it translates what an
operator asks into a call to the application and holds no business rule.

| Command | Role | Needs |
| --- | --- | --- |
| `es:migrate` | Creates the tables of the event store and of the other adapters | |
| `projection:run`, `projection:rebuild`, `projection:status`, `projection:stop` | Runs and controls projections | |
| `webhook:deliver`, `webhook:dead-letters`, `webhook:retry` | Sends outgoing webhooks and deals with failures | [`acta/webhook`](../webhook) |
| `openapi:lint` | Checks the OpenAPI contract | [`acta/http`](../http) |
| `auth:login`, `auth:logout` | Device-flow login from the terminal | [`acta/oidc`](../oidc) |

They are plain Symfony Console commands with constructor dependencies: register them in
any console application, `laminas-cli` included.

## Requirements

- PHP 8.5 or later
- `acta/event-sourcing`, `symfony/console` and a PSR-20 clock
- For `es:migrate` on PostgreSQL: `ext-pdo_pgsql` and PostgreSQL 14 or later
- Optional: `acta/webhook`, `acta/http` and `acta/oidc` for the commands above, and
  `ext-pcntl` to stop long-running commands cleanly

A command whose library is not installed is simply not registered: nothing here loads
`acta/webhook`, `acta/http` or `acta/oidc` unless you construct the command.

## Installation

```bash
composer require acta/console
```

## Quick start

```php
$registry = new ProjectionRegistry()
    ->add('accounts', fn() => $accounts->projection($projections));

$application = new Application('bank');
$application->addCommands([
    new MigrateCommand($pdo, new StatementsSchema('event store', $schema->statements())),
    new ProjectionRunCommand($registry),
    new ProjectionRebuildCommand($registry),
    new ProjectionStatusCommand($projections, $registry),
    new ProjectionStopCommand($projections),
]);
$application->run();
```

```bash
bin/console es:migrate                  # at every deployment: the statements are idempotent
bin/console projection:run accounts     # a worker; stops cleanly on SIGTERM
bin/console projection:status
```

## Documentation

[Console](docs/console.md) covers each command, registering projections and schemas,
and writing the commands of your own business.

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
