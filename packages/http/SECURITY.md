# Security policy

## Reporting a vulnerability

Please report security issues privately to the maintainers rather than through the
public issue tracker. Describe the problem, the affected version and, if possible, a way
to reproduce it. You will receive an acknowledgement, then a fix or a mitigation plan.

## Notes for integrators

- **Authentication is yours to plug in.** `SecurityMiddleware` enforces which scheme and
  which scopes each operation requires; verifying credentials is the job of the
  `Authenticator` you register for each scheme. An operation whose scheme has no
  authenticator answers with a server error.
- **An operation without `security` is public.** Declare a top-level `security` in the
  contract so that operations are protected unless they opt out with `security: []`.
- **Keep `debug` off in production.** `ProblemDetailsMiddleware` then never sends the
  message or the trace of an unexpected exception. The detail of an `HttpException` is
  always sent: do not put anything internal in it.
- **Validation errors quote the constraints of the contract**, such as patterns and
  enumerations, which are public by nature. Of what the client sent, they repeat at
  most the names of undeclared properties, never values.
- **Idempotency keys are stored hashed**, together with the whole response of the first
  request. Scope the keys to the authenticated caller with the `scope` argument of
  `IdempotencyMiddleware`, so that one caller cannot replay the response of another.
- **The compiled contract is a PHP file that gets executed.** Write it to a directory
  the web server cannot write to at runtime.
- The table name given to `PostgresIdempotencyStore` is validated as a plain SQL
  identifier; every other value is bound as a query parameter.
