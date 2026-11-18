# Security policy

## Reporting a vulnerability

Please report security issues privately to the maintainers rather than through the
public issue tracker. Describe the problem, the affected version and, if possible, a way
to reproduce it. You will receive an acknowledgement, then a fix or a mitigation plan.

## Notes for integrators

- `auth:login` stores tokens through the `TokenStorage` you give it; see the security
  notes of `acta/oidc`.
- `es:migrate` runs the statements of the schemas it is given: only register schemas
  from code you trust. Table names of the PostgreSQL adapters are validated as plain
  SQL identifiers; every other value is bound as a query parameter.
- `webhook:retry` sends a stored body again to the URL of its subscription: see the
  security notes of `acta/webhook` on subscriber URLs.
- The commands act with the rights of whoever runs them: they are for operators, and
  nothing in them checks who that is.
