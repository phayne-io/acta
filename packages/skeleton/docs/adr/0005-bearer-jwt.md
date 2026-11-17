# 0005. The API is an OAuth 2.0 resource server

- Status: accepted
- Date: 2026-11-17

## Context

The API is called by applications and services on behalf of people. It should not
handle passwords or sessions, nor depend on one identity product.

## Decision

Authentication is delegated to an OpenID Connect provider. The API accepts Bearer
access tokens in JWT form and verifies them locally (`acta/oidc`): signature against
the keys the provider publishes (JWKS, discovered from the issuer), issuer, audience,
expiry. Signature algorithms are an allow-list; `none` and symmetric algorithms are
not on it.

Authorization at the edge is by scope: each operation states the scopes it requires in
the contract, and nothing about them is written in PHP. Finer rules — who may act on
which account — are business rules and belong to the application.

The subject of the token becomes the `actor` of every event the request causes.

## Consequences

- No credentials are stored. Changing provider is a change of configuration.
- No call to the provider on each request: keys are cached and refreshed when an
  unknown key shows up, which follows rotations.
- A token cannot be revoked before it expires: keep access tokens short-lived.
- Opaque tokens, token introspection, DPoP and mutual TLS are not supported. A provider
  that only issues opaque access tokens needs another `Authenticator`.
- An outage of the provider at the moment keys must be fetched is reported as a 503,
  not as a refusal.
