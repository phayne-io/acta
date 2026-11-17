# 0003. The OpenAPI contract is written by hand and enforced at run time

- Status: accepted
- Date: 2026-11-17

## Context

An API is a promise to its clients. A contract generated from code describes whatever
the code happens to do, and changes when the code does; a contract kept beside the
code drifts from it. Clients need neither.

## Decision

`openapi/openapi.yaml` (OpenAPI 3.1) is written by hand, reviewed like code, and is the
source of truth. At run time `acta/http` reads it to route requests, to decide which
scopes an operation requires, to validate parameters and bodies, and to check
responses. Request handlers are bound to operations by `#[Operation('operationId')]`.

Tests assert that the contract is valid and that every operation has exactly one
handler. Outgoing webhooks are described in the `webhooks` section and validated
against it before they are queued.

Errors are RFC 9457 problem documents.

## Consequences

- The contract cannot lie: what it does not allow is refused before any code of the
  application runs, and a response it does not describe is caught in tests.
- Designing an operation starts with its contract, which is where the conversation with
  its clients happens anyway.
- Validation is not written in PHP. Rules that a schema cannot express are business
  rules and belong to the domain.
- A change to the API is a change to a YAML file that shows up as such in review.
- Validating against the contract costs time on each request; the contract is compiled
  to PHP once to keep that small.
