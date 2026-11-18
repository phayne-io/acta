# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Console commands: `es:migrate`, `projection:run`, `projection:rebuild`,
  `projection:status` and `projection:stop`.
- With `acta/webhook`: `webhook:deliver`, `webhook:dead-letters` and `webhook:retry`.
- With `acta/http`: `openapi:lint`. With `acta/oidc`: `auth:login` and `auth:logout`.
- `ProjectionRegistry`, `Schema`, `StatementsSchema` and `ConsoleMetadata`.

### Changed

- The package is `acta/console`, split from `acta/console-webhook`. Its classes keep
  the `Acta\Console` namespace. `acta/webhook` is no longer required: install it to get
  the `webhook:*` commands.
