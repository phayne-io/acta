# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- OpenAPI 3.1 contract: `ContractLoader` for JSON, YAML and compiled PHP files, the
  `Contract` model of operations, parameters, bodies, responses, security requirements
  and webhooks, and `ContractLinter`.
- Routing from the paths of the contract: `Router` and `RoutingMiddleware`.
- `SecurityMiddleware` enforcing the security requirements of each operation through
  the `Authenticator` port.
- Request validation: parameters decoded with their serialization style and typed from
  their schema, JSON and form bodies, all collected into an `Input`.
- Response validation, failing or logging.
- RFC 9457 errors: `ProblemDetails`, `HttpException`, `ProblemDetailsMiddleware` and the
  `ExceptionMapper` port.
- W3C Trace Context: `TraceContext` and `CorrelationMiddleware`.
- `Idempotency-Key` support: `IdempotencyMiddleware`, the `IdempotencyStore` port, and
  in-memory and PostgreSQL stores.
- Handlers attached to operations with `#[Operation]`, `OperationHandlers` with lazy
  PSR-11 resolution, `OperationDispatcher`, and a `Responder` built on PSR-17.
- Optional bridge to `acta/event-sourcing`: `MessageFactory`, `MetadataFactory`,
  `IfMatch` and `EventSourcingExceptionMapper`.
- Testing support: `ContractAssertions`, the `IdempotencyStoreContract` suite, and
  `Testing\Pipeline`, a minimal PSR-15 runner for tests and examples.
- `RouteMatch::attach()`, for an application that routes with a router of its own.

### Changed

- `Acta\Http\Pipeline` is now `Acta\Http\Testing\Pipeline`: running the middlewares
  of an application is the job of its PSR-15 stack, not of this library.
- Runnable banking example built on `acta/event-sourcing` and `moneyphp/money`.
