# Security policy

## Reporting a vulnerability

Please report security issues privately to the maintainers rather than through the
public issue tracker. Describe the problem, the affected version and, if possible, a way
to reproduce it. You will receive an acknowledgement, then a fix or a mitigation plan.

## Notes for integrators

### Incoming webhooks

- **The signature is the only authentication.** The receiver must get the body exactly
  as it was sent: a proxy or a middleware that re-encodes JSON breaks verification.
- **Prefer schemes with a signed timestamp.** `HmacSignature` without `timestampHeader`
  cannot refuse a replayed webhook once its delivery record has expired.
- **Secrets are compared in constant time** and are marked `#[SensitiveParameter]`. Use
  one secret per sender and rotate them with the list form.
- **The endpoint is public.** Bodies are limited to 1 MiB by default; add rate limiting
  in front of it.
- **Translators receive attacker-influenced data** once a secret leaks. Validate what
  they read, and keep commands as narrow as their purpose.

### Outgoing webhooks

- **Subscriber URLs are a server-side request forgery vector** when subscribers choose
  them. `Subscription` only requires `https`: it does not resolve the host. Refuse
  private, loopback and link-local addresses when registering a subscription, and use
  an HTTP client that does not follow redirects and cannot reach your internal network.
- **Stored bodies and secrets.** The delivery queue keeps the body of every webhook
  until it is purged, dead letters indefinitely; subscription secrets are yours to
  store, encrypted at rest if your threat model asks for it.
- **Only mapped events leave.** Review `IntegrationEventMapper` implementations as you
  would review an API: what they return is public.
- **Deliveries are at-least-once.** Document to subscribers that they must verify the
  signature and deduplicate on `webhook-id`.
