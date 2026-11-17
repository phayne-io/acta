# Operations

## Processes

The application is one image run several ways:

| Process | Command | How many |
| --- | --- | --- |
| API | `php-fpm` behind a web server, or any PSR-15 runner | As many as the load needs |
| Projection worker | `bin/console projection:run <name>` | One per projection — a second one exits, the first holds the lock |
| Webhook sender | `bin/console webhook:deliver --watch` | One or more; deliveries are reserved, not shared |
| Migration | `bin/console es:migrate` | Once per deployment, before the rest |

`bin/console projection:status` lists the projections; there is always at least
`webhooks`, the outbox of outgoing webhooks, next to one per read model.

Workers stop cleanly on `SIGTERM`: they finish their batch, save their position and
exit. Let your orchestrator restart them.

## Database

```sh
bin/console es:migrate --dry-run   # prints the statements
bin/console es:migrate             # creates what does not exist yet
```

`es:migrate` creates missing tables and indexes; it does not alter existing ones. The
event store is append-only and its schema is stable. Read models are disposable: to
change one, change its table and its projection, then rebuild it —

```sh
bin/console projection:rebuild banking_accounts
```

— which empties it and replays the whole event log. For a large log, build the new
version under a new name next to the old one and switch readers when it has caught up.

Housekeeping, from a scheduler: idempotency keys, received webhook identifiers and
delivered webhooks expire; each PostgreSQL adapter has a `purge` method. See the
documentation of `acta/http` and `acta/console-webhook`.

## Webhooks

```sh
bin/console webhook:dead-letters        # what was given up after the last retry
bin/console webhook:retry <delivery>    # puts it back in the queue
```

A delivery is retried with growing delays for about a day before it is parked. A
notification whose body does not match the `webhooks` section of the contract is never
sent: it is parked at once and logged as an error, since it is a defect.

Give each subscriber its own secret (`StandardWebhooks::generateSecret()`). Subscriber
URLs must be `https`. If subscribers can register their own URL, filter the addresses
they may point to before accepting them: the sender will call whatever it is given.

## Deployment

```sh
docker build --target prod -t my-app .
```

The image contains the code and its production dependencies, runs as `www-data` and
writes only to `data/cache`. Configure it with the environment variables of
[Configuration](configuration.md). In production:

- leave `APP_DEBUG` off and `PROJECTIONS_AFTER_COMMAND` empty;
- keep `HTTP_RESPONSE_VALIDATION=log`, and alert on the log line it writes;
- serve the API over TLS and pass the `Authorization` header to PHP (see `docker/nginx`);
- share the cache of identity-provider keys between instances, or accept one fetch per
  instance;
- run `es:migrate` before starting the new version.

`GET /health` answers as soon as the process does and checks nothing else, on purpose:
use it as the liveness probe.

## Logs and traces

One JSON object per line on standard error. Each request carries a W3C `traceparent`,
continued from the caller's or started here and returned in the response; its trace
identifier is the `correlation_id` of every command and event the request causes, and
the `trace_id` of the problem document when it fails. One identifier follows a request
from the client to the event store and on to the webhooks it triggers.

## Logging in from the console

`bin/console auth:login` logs a person in with the device flow of your identity
provider and stores the tokens in `data/token.json`; `auth:logout` forgets them. Use
them from your own console commands, through `Acta\Oidc\Client\StoredTokenProvider`, to
call protected APIs in the name of that person. The development provider of
`compose.yaml` does not implement the device flow.
