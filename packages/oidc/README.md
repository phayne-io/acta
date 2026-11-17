# Acta OIDC

OAuth 2.0 and OpenID Connect for PHP 8.5+, on both sides of the token:

- as a **resource server**, it verifies the Bearer JWT access tokens an API receives,
  with the provider's keys found through discovery and JWKS;
- as a **client**, it obtains tokens: client credentials, authorization code with PKCE,
  device authorization, refresh.

Everything talks to the provider through PSR-18 and PSR-17, caches through PSR-16, and
protects PSR-15 pipelines. It works with any standards-compliant provider: Keycloak,
Microsoft Entra ID, Auth0, Okta, Cognito, and others.

- **Discovery** of the provider metadata (OpenID Connect, then RFC 8414), cached.
- **JWKS** cached, following key rotations without letting forged tokens hammer the
  provider.
- **Verification** with an allow-list of asymmetric algorithms (`none` and shared
  secrets do not exist here), issuer, audience, validity period and clock leeway.
- **A `Principal`** with subject, scopes and roles, wherever the provider puts them.
- **`BearerAuthMiddleware`** answering as RFC 6750 specifies.
- **Client authentication** with `client_secret_basic`, `client_secret_post` or
  `private_key_jwt`, or none for public clients.
- **ID tokens always verified**: signature, audience, nonce, access token hash.
- **Token providers** caching and renewing tokens, and a PSR-18 decorator adding them
  to outgoing requests.
- **A fake identity provider** to test your application without network.

## Requirements

- PHP 8.5 or later, with `ext-openssl`; `ext-sodium` for EdDSA
- `web-token/jwt-library`, for the signatures only
- PSR interfaces: HTTP client, messages and factories, server handlers and middlewares,
  simple cache, clock, log
- Any PSR-18 client and PSR-17 factories, such as Guzzle or Symfony HttpClient with
  `laminas/laminas-diactoros` or `nyholm/psr7`

## Installation

```bash
composer require acta/oidc
```

## Quick start

The snippets below follow [`examples/quickstart.php`](examples/quickstart.php), which
runs both sides against an in-memory provider.

### What both sides share

```php
$transport = new Transport($httpClient, $requestFactory, $streamFactory);   // PSR-18 and PSR-17
$provider = new Discovery('https://idp.example.com/realms/acme', $transport, $cache);
$keys = new JwksProvider($provider, $transport, $cache);
```

Pass a shared PSR-16 cache: without one, the metadata and the keys are fetched again by
every PHP-FPM request.

### Protecting an API

```php
$verifier = new AccessTokenVerifier(new JwtVerifier($provider, $keys), audience: 'https://api.example.com');

$app->pipe(new BearerAuthMiddleware($verifier, $responseFactory, $streamFactory, scopes: ['accounts:read']));
```

Handlers then read who is calling:

```php
$principal = Principal::from($request);

$principal->subject;                       // 'ada'
$principal->hasScope('accounts:write');
$principal->hasRole('teller');
$principal->claims->string('email');
```

| Request | Answer |
| --- | --- |
| No token | `401`, `WWW-Authenticate: Bearer` |
| Refused token | `401`, `Bearer error="invalid_token", error_description="The token has expired."` |
| Valid token without a required scope | `403`, `Bearer error="insufficient_scope", scope="accounts:read"` |
| Provider unreachable and keys not cached | `503` |

### Calling a protected service

```php
$client = new OAuthClient('billing-service', new ClientSecretBasic($secret), $provider, $transport);
$tokens = new ClientCredentialsTokenProvider($client, ['accounts:read'], $cache);

$http = new AuthenticatedHttpClient($httpClient, $tokens);   // a PSR-18 client like any other
$response = $http->sendRequest($request);                    // carries "Authorization: Bearer …"
```

The token is requested once, cached, renewed shortly before it expires, and renewed at
once if the service answers 401.

### Logging a user in

```php
// 1. Redirect the user. Keep $login in their session.
$login = $client->authorizationRequest('https://app.example.com/callback', ['openid', 'profile']);
header('Location: ' . $login->url);

// 2. On the callback, exchange the code. State, PKCE, nonce and ID token are all checked.
$tokens = $client->exchangeCode($login, $request->getQueryParams());

$tokens->idTokenClaims->string('sub');
$client->userInfo($tokens)->string('email');
```

### Logging in from a command-line tool

```php
$cli = new OAuthClient('bank-cli', new PublicClient(), $provider, $transport);
$device = $cli->requestDeviceAuthorization(['openid', 'accounts:read']);

echo "Open {$device->verificationUri} and enter {$device->userCode}\n";

new FileTokenStorage($home . '/.config/bank/tokens.json')->save($cli->pollDeviceToken($device));
```

## Documentation

| Guide | What it covers |
| --- | --- |
| [Resource server](docs/resource-server.md) | Discovery, keys, what is verified, scopes and roles per provider, the middleware, use with `acta/http` |
| [Client](docs/client.md) | The four grants, client authentication, ID tokens, token providers, storage |
| [Testing](docs/testing.md) | The fake identity provider: tokens, logins, rotations, outages |

Every public class and method is documented in the source as well.

## Out of scope

Opaque access tokens and token introspection, DPoP and mutual-TLS sender-constrained
tokens, encrypted tokens (JWE), signed or encrypted UserInfo responses, dynamic client
registration, and acting as an identity provider.

## Development

```bash
composer install
composer check   # coding standard, static analysis, tests
```

No external service is needed: the tests run against the fake identity provider.

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

Released under the [MIT License](LICENSE).
