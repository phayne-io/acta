# Console

The commands are plain Symfony Console commands: `#[AsCommand]`, constructor
dependencies, no container lookups. Register them in any console application.

```php
$application = new Application('bank');
$application->addCommands([
    new MigrateCommand($pdo, ...$schemas),
    new ProjectionRunCommand($registry),
    new ProjectionRebuildCommand($registry),
    new ProjectionStatusCommand($projections, $registry),
    new ProjectionStopCommand($projections),
    new WebhookDeliverCommand($sender),
    new WebhookDeadLettersCommand($queue),
    new WebhookRetryCommand($queue),
    new OpenApiLintCommand('openapi/openapi.yaml'),
    new AuthLoginCommand($oauthClient, $tokenStorage, ['openid', 'accounts:read']),
    new AuthLogoutCommand($tokenStorage),
]);
```

With `laminas-cli`, list them under `laminas-cli.commands` and let the container build them.

## es:migrate

Creates the tables of every adapter. A schema is a name and idempotent statements, which
each Acta adapter exposes:

```php
new MigrateCommand(
    $pdo,
    new StatementsSchema('event store', new PostgresSchema()->statements()),
    new StatementsSchema('incoming webhooks', $deliveryLog->statements()),
    new StatementsSchema('outgoing webhooks', $deliveryQueue->statements()),
    new StatementsSchema('idempotency keys', $idempotencyStore->statements()),
);
```

```bash
bin/console es:migrate             # safe at every deployment
bin/console es:migrate --dry-run   # prints the SQL instead
```

Each schema is installed in one transaction. Implement `Schema` for tables of your own,
read models included. The command creates what is missing; it is not a migration tool
for changing existing tables.

## Projections

Projections are registered by name, as the closure that builds them:

```php
$registry = new ProjectionRegistry()
    ->add('balances', fn() => $projections->readModelProjection('balances', $balances)->fromCategory('account')->when([/* … */]))
    ->add('webhooks', fn() => $outbox->projection($projections));
```

| Command | What it does |
| --- | --- |
| `projection:run <name>` | Catches up with the log, then follows it until stopped. One process per projection, under a supervisor. |
| `projection:run <name> --once` | Returns once caught up: for schedulers and deployments. |
| `projection:rebuild <name>` | Empties the read model and replays the log. Asks for confirmation; `--force` skips it. |
| `projection:status` | Lists projections, their status and position; registered ones that never ran included. |
| `projection:stop <name>` | Asks a projection running in another process to stop after its batch. |

`projection:run` stops cleanly on SIGINT and SIGTERM, with `ext-pcntl`: the current batch
finishes and its checkpoint is written. Starting a projection that already runs
elsewhere fails with an explicit message.

## Webhooks

| Command | What it does |
| --- | --- |
| `webhook:deliver` | Attempts the deliveries that are due, then returns. Options: `--limit`. |
| `webhook:deliver --watch` | Keeps going, pausing `--interval` seconds when nothing is due. |
| `webhook:dead-letters` | Lists the deliveries given up, with the reason. |
| `webhook:retry <delivery>...` | Makes dead letters due again. |

Require `acta/webhook`, whose guide on outgoing webhooks says what a delivery and a dead
letter are.

## openapi:lint

```bash
bin/console openapi:lint openapi/openapi.yaml
```

Exits with 1 and the list of issues when the contract has defects: one line in the
continuous integration. Requires `acta/http`.

## auth:login

Logs the operator in with the device authorization grant: the command prints a URL and
a code, the operator approves in a browser, and the tokens are saved in the
`TokenStorage`. Commands calling protected services then take a `StoredTokenProvider`.
Requires `acta/oidc`.

```bash
bin/console auth:login --scope openid --scope accounts:write
bin/console auth:logout
```

## Business commands

A command of your application is a primary adapter, like an HTTP handler: it parses its
arguments, builds a command and dispatches it. `ConsoleMetadata` gives its messages the
same traceability:

```php
#[AsCommand('account:close', 'Closes an account')]
final class CloseAccountCommand extends Command
{
    public function __construct(private readonly CommandBus $commands)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->commands->dispatch(
            new CloseAccount(AccountId::fromString($input->getArgument('account'))),
            ConsoleMetadata::create(),   // channel: cli, actor: the system user, a correlation id
        );

        return self::SUCCESS;
    }
}
```
