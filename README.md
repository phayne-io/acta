# Acta

A foundation for hexagonal, event-sourced PHP applications: a set of libraries and a
project skeleton targeting PHP 8.5 and later.

| Package | Role | Status |
| --- | --- | --- |
| [`acta/event-sourcing`](packages/event-sourcing) | Messages, buses, event store, aggregates, projections, snapshots, PSR-3 logging; in-memory and PostgreSQL adapters | Available |
| [`acta/http`](packages/http) | HTTP driving adapter: an OpenAPI 3.1 contract drives routing, security, validation and RFC 9457 errors, on PSR-7/15/17 | Available |
| [`acta/oidc`](packages/oidc) | OAuth 2.0 / OpenID Connect: Bearer JWT verification with discovery and JWKS, client flows, a fake provider for tests | Available |
| [`acta/console-webhook`](packages/console-webhook) | Operational console commands; signed incoming webhooks translated to commands; outgoing webhooks with outbox, retries and dead letters | Available |
| [`acta/skeleton`](packages/skeleton) | Project template wiring the packages together on Mezzio: a worked Banking context, `make:*` generators, Docker, CI | Available |

Each package lives under `packages/` with its own `composer.json`, tests and
documentation.
