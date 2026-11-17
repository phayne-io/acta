# Security policy

## Reporting a vulnerability

Please report security issues privately to the maintainers rather than through the
public issue tracker. Describe the problem, the affected version and, if possible, a way
to reproduce it. You will receive an acknowledgement, then a fix or a mitigation plan.

## Notes for integrators

- **Set the audience.** `AccessTokenVerifier` accepts an empty audience list, which
  disables the check: a token the provider issued for another API is then accepted.
- **Use a shared cache and an HTTP client with timeouts.** Verification blocks on the
  provider when its metadata or keys are not cached.
- **Algorithms.** The defaults are RS256, PS256, ES256 and EdDSA. `none` and the HMAC
  algorithms are not implemented and cannot be enabled.
- **Tokens are read from the `Authorization` header only**, never from the query string
  or a form body.
- **Bearer tokens are not bound to their sender.** Whoever holds one can use it: DPoP
  and mutual TLS are out of scope. Keep access tokens short-lived.
- **Stored tokens are secrets.** `TokenSet::toArray()`, `AuthorizationRequest::toArray()`
  and `FileTokenStorage` hold access tokens, refresh tokens and PKCE verifiers in clear.
  `FileTokenStorage` restricts its file to its owner; prefer the keychain of the
  operating system when one is available.
- **Secrets are marked `#[SensitiveParameter]`** so that they do not appear in stack
  traces. Do not log token sets or client authentication objects.
- **`Discovery` requires `https`**, except for loopback hosts. `allowInsecure` is for
  development environments only.
- **`FakeIdentityProvider` is a test double.** Never expose it.
