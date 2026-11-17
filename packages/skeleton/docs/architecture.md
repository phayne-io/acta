# Architecture

## Contexts and layers

The application is a set of business contexts, each in its own directory under `src/`,
plus two directories that are not business:

- `src/Platform` wires the libraries together: container factories, the HTTP and console
  plumbing, the generators. It knows no context.
- `src/Shared` holds the little that contexts share. Keep it small.

Every context has the same four layers:

| Layer | Holds | May depend on |
| --- | --- | --- |
| `Domain` | Aggregates, events, value objects, domain exceptions, repository ports | The aggregate and message types of `acta/event-sourcing`, value-object libraries (`moneyphp/money`), PHP |
| `Application` | Commands, queries, their handlers, the ports queries read from | `Domain`, `Shared`, `acta/event-sourcing` |
| `Infrastructure\Primary` | What drives the application: request handlers, console commands, webhook translators | `Application`, `Domain`, the Acta libraries, PSR interfaces |
| `Infrastructure\Secondary` | What the application drives: repositories, read models, projections, published events | `Application`, `Domain`, the Acta libraries, PDO |

Three rules follow, and all three are checked on every build:

1. **Dependencies point inwards.** The domain does not know the application, which does
   not know the adapters. No HTTP, SQL or framework type appears in `Domain` or
   `Application`. — `composer architecture` (Deptrac, `deptrac.yaml`)
2. **Primary and secondary adapters do not know each other.** They meet through the
   application. — same check
3. **A context does not reach into another.** Two contexts talk through integration
   events, or through a port one declares and the other implements in its
   infrastructure. — `tests/Architecture/ContextIsolationTest.php`

## The path of a command

`POST /accounts/{accountId}/deposits`, from the socket to the event store:

```
public/index.php
 └─ config/pipeline.php
     ├─ CorrelationMiddleware          a trace for the request (W3C Trace Context)
     ├─ ProblemDetailsMiddleware       every failure below becomes an RFC 9457 document
     ├─ RoutingMiddleware              finds the operation "depositMoney" in the contract
     ├─ SecurityMiddleware             verifies the Bearer JWT and the scope the operation requires
     ├─ RequestValidationMiddleware    checks parameters and body against the contract
     ├─ IdempotencyMiddleware          replays the first response when the request is a retry
     ├─ ResponseValidationMiddleware   checks the response against the contract
     └─ OperationDispatcher
         └─ Primary\Http\DepositMoneyAction         turns the request into a command
             └─ CommandBus                           logs, carries the actor and the trace, opens a transaction
                 └─ Application\Command\DepositMoneyHandler   loads the account, asks it, saves it
                     └─ Domain\Account::deposit()             checks the rules, records MoneyDeposited
                         └─ Secondary\Persistence\EventSourcedAccountRepository   appends to the event store
```

Nothing in this chain is specific to HTTP below the action: `banking:account:open`
dispatches the same commands from the console, and the payment provider's webhooks are
translated into the same `DepositMoney`.

What the pipeline refuses never reaches the application: an unknown path, a missing or
insufficient token, a body the contract does not allow. What the domain refuses is a
`BankingRuleViolated`, which `BusinessRuleMapper` reports as a 422 with a stable `type`.

## The path of a query

Queries do not load aggregates. A projection reads the events and keeps a read model,
shaped for its readers; queries answer from it:

```
event store ──▶ AccountDirectoryProjection ──▶ banking_accounts (table)
                                                     ▲
GET /accounts/{id} ─▶ GetAccountAction ─▶ QueryBus ─▶ GetAccountHandler ─▶ AccountDirectory (port)
```

The read model can be dropped and rebuilt from the events at any time
(`bin/console projection:rebuild banking_accounts`), which is what makes it cheap to
change.

## Consistency

**Writes are strongly consistent.** A command is one transaction; an aggregate is
appended to with the version it was loaded at, so two concurrent writers cannot both
win. A client can widen that guarantee to the time it took a person to decide: it sends
the `ETag` it read in `If-Match`, and the command is refused with a 409 if the account
moved since.

**Reads are eventually consistent.** Projections run in their own processes and follow
the event log, typically within milliseconds: the event store notifies them through
PostgreSQL `LISTEN`/`NOTIFY`. A `GET` issued right after a `POST` may still answer with
the previous state, or with a 404 for a resource just created. That is why command
endpoints answer with what they know for sure — an identifier and a `Location` — and
not with a representation.

Where no worker runs, on a developer machine or in tests, `PROJECTIONS_AFTER_COMMAND`
lists projections to bring up to date right after each command. It is a convenience and
not a guarantee; leave it empty in production. For the rare read model that must never
lag, `acta/event-sourcing` documents inline projections, updated in the transaction of
the command.

**Outgoing webhooks are delivered at least once.** The `webhooks` projection writes one
delivery per subscriber to an outbox; `webhook:deliver` sends them, retries with
back-off and parks what keeps failing as dead letters. Subscribers deduplicate on the
`id` of the notification.

**Incoming webhooks are handled once.** The receiver records each delivery identifier in
the same transaction as the events it causes; a redelivery is acknowledged and ignored.

## Where things are decided

| Question | Answer lives in |
| --- | --- |
| Which paths exist, what they accept and return, which scopes they need | `openapi/openapi.yaml` |
| What the business allows | `src/<Context>/Domain` |
| What a context offers and through which adapters | `src/<Context>/ConfigProvider.php` |
| The order of the HTTP middlewares | `config/pipeline.php` |
| Settings that change between environments | Environment variables, read in `config/autoload/global.php` |
| How the libraries are assembled | `src/Platform/Container` |

## The container

Services are built by `laminas-servicemanager`. A class whose constructor only asks for
other services needs no factory and no registration: handlers, request handlers and
console commands are built from their constructor. A context writes a factory only for
what needs a setting or a choice — see `PaymentsWebhookSource::create()` and
`AdapterChoice` in `src/Banking/ConfigProvider.php`.

Messages, handlers and operations are found by attribute — `#[Handles]`, `#[Operation]`,
`#[MessageName]`, `#[AsCommand]` — on classes the context lists in its `ConfigProvider`.
Nothing is discovered by scanning directories: what the application does is written
down in one file per context.
