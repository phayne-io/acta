# Security policy

## Reporting a vulnerability

Please report security issues privately to the maintainers rather than through the
public issue tracker. Describe the problem, the affected version and, if possible, a way
to reproduce it. You will receive an acknowledgement, then a fix or a mitigation plan.

## Notes for integrators

- Table and channel names given to the PostgreSQL adapters are validated as plain SQL
  identifiers; every other value is bound as a query parameter.
- `PostgresSnapshotStore` uses PHP's `unserialize()` on the content of the snapshots
  table by default. Treat write access to that table as code execution, or provide your
  own serialization callbacks.
- Message metadata is stored and forwarded as is. Do not put secrets in it.
