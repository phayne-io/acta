# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Signature schemes behind the `SignatureScheme` port: `StandardWebhooks` and
  `HmacSignature`, with timestamp tolerance and secret rotation.
- Incoming webhooks: `WebhookReceiver` (PSR-15), `WebhookSource`, the `WebhookTranslator`
  port, and the `DeliveryLog` port with in-memory and PostgreSQL adapters.
- Outgoing webhooks: `IntegrationEvent` and the `IntegrationEventMapper` port,
  `Subscription` and `SubscriptionRepository`, `WebhookOutbox` fed by a projection, the
  `DeliveryQueue` port with in-memory and PostgreSQL adapters, `WebhookSender`,
  `RetryPolicy` and dead letters.
- `ContractPayloadValidator`, validating outgoing webhooks against an OpenAPI contract
  with `acta/http`.
- Console commands: `es:migrate`, `projection:run`, `projection:rebuild`,
  `projection:status`, `projection:stop`, `webhook:deliver`, `webhook:dead-letters`,
  `webhook:retry`, `openapi:lint`, `auth:login` and `auth:logout`.
- `ProjectionRegistry`, `Schema`, `StatementsSchema` and `ConsoleMetadata`.
- PSR-3 logging of refused, handled and failed webhooks, and of every delivery attempt.
- Testing support: the `DeliveryLogContract` and `DeliveryQueueContract` suites.
