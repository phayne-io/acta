# Contributing

Thank you for considering a contribution.

## Getting set up

```bash
composer install
composer check   # coding standard, static analysis, tests
```

No external service is needed: the tests run against `FakeIdentityProvider`, with
`laminas/laminas-diactoros` as PSR-7 implementation, a development dependency.

## What a pull request needs

- **Tests.** New behaviour comes with tests; a bug fix comes with a test that fails
  without it. A new check on tokens comes with the token it refuses.
- **Documentation.** Public classes and methods are documented in the source: say what
  they are for and what a caller must know, not how they work. Update the guides in
  `docs/` and add a line to `CHANGELOG.md` under "Unreleased".
- **Green checks.** `composer check` must pass: PER coding style, PHPStan at the max
  level for `src`, the whole test suite.

## Design rules

- **Refuse by default.** A token is rejected unless every check passes. An algorithm
  is unavailable unless it was allowed. A doubt is a refusal, an outage is an error,
  and neither is ever a pass.
- **Cryptography is delegated.** Signatures go through the `SignatureVerifier` and
  `Signer` ports, implemented with `web-token/jwt-library`. Nothing else in the package
  may use that library, and no cryptographic primitive is written here.
- **PSR interfaces only.** HTTP goes through PSR-18 and PSR-17, caching through PSR-16,
  time through PSR-20. No HTTP client, cache or PSR-7 implementation is required.
- **Messages are for the other side.** What an `InvalidToken` says may be sent back to
  whoever presented the token: it must not reveal keys, configuration or internals.
- **The fake provider stays honest.** `FakeIdentityProvider` must check what a real
  provider checks, or tests against it prove nothing.
- **Backward compatibility.** The public API follows semantic versioning.

## Reporting a security issue

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
