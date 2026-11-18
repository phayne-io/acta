# Acta

Libraries and a project skeleton for hexagonal, event-sourced applications in PHP 8.5+.
This file tells an AI assistant how to work in this repository. Humans: the same rules
apply to you; the READMEs and `docs/` of each package go deeper.

## Layout

A monorepo. Each package under `packages/` is a standalone Composer package with its own
`composer.json`, `vendor/`, tests, docs and CI workflow (`.github/workflows/<package>.yml`).

| Package | Namespace | What it is |
| --- | --- | --- |
| `packages/event-sourcing` | `Acta\EventSourcing` | Messages, buses, event store, aggregates, projections, snapshots; in-memory and PostgreSQL adapters |
| `packages/http` | `Acta\Http` | HTTP primary adapter driven by an OpenAPI 3.1 contract: routing, security, validation, RFC 9457 errors (PSR-7/15/17) |
| `packages/oidc` | `Acta\Oidc` | OAuth 2.0 / OIDC: Bearer JWT verification (discovery, JWKS), client flows, a fake provider for tests |
| `packages/webhook` | `Acta\Webhook` | Incoming webhooks translated to commands; outgoing webhooks with outbox, retries and dead letters |
| `packages/console` | `Acta\Console` | Operational console commands: migrations, projections, webhook delivery, contract linting, login |
| `packages/skeleton` | `App` | Project template wiring the five on Mezzio, with a `Banking` example context and `make:*` generators |

Dependencies between packages only go this way: `event-sourcing` depends on no other
package; `http` and `oidc` do not know each other; `webhook` builds on `event-sourcing`
and uses `http` only in an optional bridge; `console` builds on `event-sourcing` and
uses `webhook`, `http` and `oidc` only in the commands made for each, as development
dependencies; `webhook` does not know `console`; `skeleton` uses all five.

Packages reach each other through Composer **path repositories** (`../event-sourcing`),
installed as symlinks. Each path repository states the version it holds
(`"options": {"versions": {"acta/x": "1.0.x-dev"}}`) and consumers require `^1.0@dev`.
Keep both: without the stated version Composer names the package after the checked-out
branch and installation fails on every branch but `main`.

The skeleton is also a template for `composer create-project`, which changes two things
there. Its path repositories end with `*` (`../http*`): Composer accepts a wildcard that
matches nothing, so that the project installs outside the monorepo, where a plain
`../http` is an error. And `bin/init-project.php`, run by `post-create-project-cmd`,
asks for a name, a domain and a namespace, and removes what only serves the monorepo
(those repositories, `compose.monorepo.yaml`, itself). Whatever carries the name
`mybank`, `MyBank` or `mybank.io` in the skeleton's README and docs is replaced by it:
keep those spellings. Likewise every `App\` is moved to the chosen namespace, in code,
strings, YAML and the generators' templates: write the namespace as `App\…`, never
assembled from pieces, so that the script finds it.

## Commands

Run from inside a package directory, never from the repository root.

```sh
composer install
composer check        # everything the CI runs for the package
composer cs           # PER coding style (dry run); composer cs:fix applies it
composer stan         # PHPStan
composer test         # PHPUnit; PostgreSQL tests are skipped when no server answers
composer test:unit    # without the PostgreSQL group (not in oidc, which has none)
```

`packages/skeleton` adds:

```sh
composer architecture    # Deptrac: dependency rules between layers
composer lint:contract   # openapi/openapi.yaml
composer mutation        # Infection on Domain and Application (needs Xdebug or PCOV)
composer serve           # http://localhost:8080 with the PHP built-in server
vendor/bin/phpstan analyse --memory-limit=1G -c phpstan.tests.neon.dist   # tests, level 8
bin/console list
```

The skeleton also has a Docker stack (see its `docs/local-stack.md`): HTTPS with a
mkcert certificate, Keycloak, Valkey. Its names come from the first three lines of
`.env`; in this repository they are `mybank`, `MyBank` and `mybank.io`.

```sh
make up                  # https://api.mybank.io: API, workers, PostgreSQL, Valkey, Keycloak
make check               # composer check in the container, PostgreSQL tests included
docker build --target prod --build-context libraries=.. -t app .   # the production image
```

The second build context is for the monorepo only: the libraries are next to the
skeleton, outside what its build may read (`packages/.dockerignore` says what of them
is sent).

PostgreSQL for the tests: the libraries read `ACTA_TEST_POSTGRES_DSN`, `_USER`,
`_PASSWORD` (default database `acta_test`, user `acta`); the skeleton reads
`TEST_DATABASE_DSN`, `_USER`, `_PASSWORD` (default `app_test`, user `app`). Defaults are
in each `phpunit.xml.dist`; `docker compose up -d` provides the server in the libraries
that have a `compose.yaml`; the skeleton's stack publishes its server on port 54320.

Before saying a change is done, run `composer check` in every package the change
touches **and in the packages that depend on it** (a change in `event-sourcing` can
break all the others). In the skeleton, also run PHPStan on the tests and, when
`src/*/Domain` or `src/*/Application` changed, `composer mutation`.

## Code conventions

- **PHP 8.5 is the floor; use it.** Readonly classes, enums, property hooks, asymmetric
  visibility, the pipe operator, `clone($object, [...])`, `#[\NoDiscard]`, `#[\Override]`,
  first-class callables, `array_find`/`array_any`/`array_all`, `new X()->method()`.
  CI also runs PHP 8.6, where failures are tolerated but should be fixed.
- **PSR-4 and PER Coding Style** (the successor of PSR-12), enforced by PHP-CS-Fixer.
  `declare(strict_types=1)` everywhere. Classes are `final` unless designed for extension;
  value objects and messages are `final readonly`.
- **PHPStan at level max on `src`**, level 8 on `tests`. Fix the cause of an error: no
  `@phpstan-ignore`, no baseline, no inline `@var` to override an inferred type, no cast
  added only to silence the analyser.
- **Document the code.** Every class and public method has a PHPDoc that says what it is
  for and what a caller must know, not how it works. Comments explain why. Code, docs
  and commit messages are in English.
- **Libraries depend on PSR interfaces only**, plus the few packages already in their
  `require`. A new runtime dependency needs a strong reason; `moneyphp/money` is for
  examples and tests, never for library code.
- **Primary adapters speak PSR-7, PSR-15 and PSR-17**; nothing types against Diactoros
  or Mezzio outside the skeleton's wiring.
- **OpenAPI 3.1 is the source of truth for HTTP I/O.** The contract is written by hand;
  routes, security requirements and validation come from it. Change the contract first,
  then the code. Errors are RFC 9457 problem documents.
- **Time comes from a PSR-20 clock.** Stores do not read the clock themselves: the
  caller passes `$now` (see `IdempotencyStore::acquire`, `DeliveryLog::claim`).

## Architecture rules of the skeleton

Each business context lives in `src/<Context>` with four layers:

- `Domain` — aggregates, events, value objects, exceptions, repository ports. Depends only
  on `Acta\EventSourcing\Aggregate`, `Acta\EventSourcing\Message`, value-object libraries
  and PHP. This includes PHPDoc references: Deptrac reads them.
- `Application` — commands, queries, handlers, read ports. Depends on `Domain`, `Shared`
  and `acta/event-sourcing`. Never on PSR-7, PDO, the framework or another context.
- `Infrastructure\Primary` — HTTP actions, console commands, webhook translators. Reaches
  the application through the command and query buses only.
- `Infrastructure\Secondary` — repositories, read models, projections, published events.

`src/Platform` wires the libraries and knows no context. `src/Shared` holds the little
that contexts share. Two contexts talk through integration events or an explicit port.
`composer architecture` and `tests/Architecture` enforce all of this.

A context plugs in through its `ConfigProvider`, which lists its events, handlers,
operations, projections, schemas and commands. Nothing is discovered by scanning. A
class whose constructor only asks for services needs no factory.

Add features with the generators (`bin/console make:context|aggregate|command|query|webhook`)
rather than by hand; they insert above the `// acta:` and `# acta:` markers, which must
stay in place. Their templates are in `templates/make`: a change to a convention goes
into the templates as well, and the generated code must still pass `composer check`.

## Tests

- New behaviour comes with tests; a bug fix comes with a test that fails without it.
- Domain rules are tested as given/when/then scenarios with `AggregateScenario`, without
  infrastructure.
- Anything observable through a port (`EventStore`, `ProjectionStore`, `IdempotencyStore`,
  `DeliveryQueue`…) belongs in the contract suites under `src/Testing`, so that every
  adapter is held to it.
- End-to-end tests of the skeleton run the same scenarios in memory and on PostgreSQL
  (`tests/Functional`), with `FakeIdentityProvider` standing in for the identity provider.
- Always run PHPUnit with a timeout when driving it from a script: a projection test that
  never stops otherwise hangs the run.
- Do not assert the exact text of messages produced by PHP itself (JSON errors, type
  errors): it changes between PHP versions.

## Git and pull requests

- Never commit to `main` directly: work on a branch and open a pull request.
- Commits are authored by the repository owner. Do **not** add `Co-Authored-By` lines,
  links to an AI session, or "Generated with/by Claude Code" mentions to commit messages
  or pull request descriptions.
- Commit messages: an imperative subject line, then a body that says why.
- Do not rewrite the history of `main`.
- Update the package's `CHANGELOG.md` ("Unreleased") and its `docs/` when behaviour or
  public API changes.
- CI: one workflow per package, triggered by changes under its path and under the
  packages it depends on. Jobs on PHP 8.6 are allowed to fail, so a green workflow can
  hide a red job: check the jobs.

## Things that are not done yet

- Nothing is published on Packagist; `composer create-project acta/skeleton` does not
  work yet. The `acta` vendor name is unconfirmed.
- The `prod` target of the skeleton's Docker image is built by CI, which also loads the
  console in it. It was run once by hand in place of the `dev` image of the local
  stack; nothing does that regularly, and it has never been deployed anywhere.
- The only identity provider anything was tested against is the Keycloak 26 of the
  skeleton's local stack: the API verifying its tokens, client credentials, and the
  device flow of `auth:login`. No hosted provider (Auth0, Microsoft Entra ID, …) yet.
