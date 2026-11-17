# Architecture decision records

Why the application is built the way it is. One file per decision, numbered, never
rewritten: a decision that no longer holds is superseded by a new record that says so.

| | Decision |
| --- | --- |
| [0001](0001-hexagonal-contexts.md) | Business contexts with hexagonal layers, checked on every build |
| [0002](0002-event-sourcing.md) | Aggregates are event-sourced, in PostgreSQL |
| [0003](0003-contract-first-api.md) | The OpenAPI contract is written by hand and enforced at run time |
| [0004](0004-asynchronous-read-models.md) | Read models are projected asynchronously |
| [0005](0005-bearer-jwt.md) | The API is an OAuth 2.0 resource server |
| [0006](0006-explicit-wiring.md) | Wiring is explicit, one `ConfigProvider` per context |

To record a decision, copy [0000-template.md](0000-template.md).
