# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Transport` over PSR-18 and PSR-17, and `ArrayCache`, the default PSR-16 cache.
- Provider metadata: `Discovery` (OpenID Connect, then RFC 8414) and `ProviderMetadata`.
- Keys: `JwksProvider` with caching and rate-limited refresh on unknown key identifiers,
  and `KeySet`.
- Token verification: `JwtVerifier`, `AccessTokenVerifier`, `Principal`, `Claims`, and
  `StandardClaimsMapper` behind the `ClaimsMapper` port.
- Resource server: `BearerAuthMiddleware`, `BearerToken` and `BearerChallenge` (RFC 6750).
- Client: `OAuthClient` with the client credentials, authorization code with PKCE,
  device authorization and refresh token grants, ID token verification, UserInfo and
  RP-initiated logout.
- Client authentication: `ClientSecretBasic`, `ClientSecretPost`, `PrivateKeyJwt` and
  `PublicClient`.
- Token providers: `ClientCredentialsTokenProvider`, `StoredTokenProvider` with
  in-memory and file storage, and the `AuthenticatedHttpClient` PSR-18 decorator.
- Signatures delegated to `web-token/jwt-library` behind the `SignatureVerifier` and
  `Signer` ports, and `KeyFactory`.
- PSR-3 logging of refused tokens, outages and key fetches.
- Testing support: `FakeIdentityProvider`.
