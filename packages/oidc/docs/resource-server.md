# Resource server

A resource server receives access tokens and has to decide, without calling the
provider for each request, whether to trust them.

## Provider metadata

```php
$provider = new Discovery('https://idp.example.com/realms/acme', $transport, $cache, ttl: 3600);
```

The issuer is the exact `iss` of the tokens. Its discovery document is read from
`/.well-known/openid-configuration`, then from the RFC 8414 location, cached, and must
name that very issuer.

The issuer must use `https`. Loopback hosts are accepted over `http` for local
development; for another host, such as a container name, pass `allowInsecure: true`.

A provider without discovery document is described by hand:

```php
$provider = new ProviderMetadata('https://idp.example.com', jwksUri: 'https://idp.example.com/keys');
```

## Keys

```php
$keys = new JwksProvider($provider, $transport, $cache, ttl: 3600, refreshInterval: 30, logger: $logger);
```

Keys are cached. When a token names a key that is not in the cache, they are fetched
again, at most once per `refreshInterval` seconds: a key rotation is picked up without
a restart, while a flood of tokens with invented key identifiers triggers one request
to the provider, not thousands.

Keys distributed out of band are given directly: `new KeySet([$jwk, …])`.

## What is verified

```php
$jwt = new JwtVerifier($provider, $keys, algorithms: ['RS256', 'PS256', 'ES256', 'EdDSA'], leeway: 30);
$verifier = new AccessTokenVerifier($jwt, audience: 'https://api.example.com', requireTokenType: false);

$principal = $verifier->verify($token);   // or throws InvalidToken
```

In this order:

1. The token is a compact JWS of reasonable size, with a JSON header and payload.
2. Its `alg` is one of the allowed ones. `none` and the HMAC algorithms are not
   implemented at all, which rules out the downgrade and key-confusion attacks.
3. A key of the provider, of the right type and with the right `kid`, verifies the
   signature. Nothing in the payload is read before this point.
4. `iss` is the issuer.
5. `exp` is present and not passed; `nbf` and `iat` are not in the future; each with
   the leeway.
6. `aud` contains the audience of this API.
7. The `typ` header, when present, is `at+jwt` or `JWT`.

`InvalidToken::$reason` tells which check failed. Its message is written for the caller
and reveals nothing about keys or configuration.

Two settings deserve attention:

- **Audience.** Without it, a token the same provider issued for another API would be
  accepted here. An empty list disables the check; only do so when the provider issues
  tokens for this API alone.
- **`requireTokenType`.** With it, only `at+jwt` tokens (RFC 9068) are accepted, which
  is what tells an access token from an ID token. Turn it on when your provider sets
  the header.

When the metadata or the keys cannot be fetched and are not cached, `ProviderUnreachable`
is thrown: it is an outage, not a refusal.

## Scopes and roles

`StandardClaimsMapper` builds the `Principal`. Providers disagree on where things are:

| Provider | Scopes | Roles |
| --- | --- | --- |
| RFC 9068, Keycloak, Auth0 | `scope`, space-separated | — |
| Microsoft Entra ID, Okta | `scp` | `roles` |
| Keycloak | `scope` | `realm_access.roles`, `resource_access.<client>.roles` |

The defaults read scopes from `scope` and `scp`, roles from `roles` and
`realm_access.roles`. Add your own paths; dots reach into nested objects, and a claim
whose own name holds dots, such as a namespaced URL, is found as well:

```php
new StandardClaimsMapper(
    scopeClaims: ['scope', 'scp'],
    roleClaims: ['realm_access.roles', 'resource_access.banking.roles', 'https://example.com/roles'],
);
```

For anything else, implement `ClaimsMapper`.

## The middleware

```php
new BearerAuthMiddleware($verifier, $responseFactory, $streamFactory, scopes: ['accounts:read'], realm: 'banking', logger: $logger);
```

It reads the token from the `Authorization` header only, stores the `Principal` as a
request attribute, and answers refusals itself with the status and the
`WWW-Authenticate` challenge of RFC 6750, plus a small problem document. Refusals are
logged at the `info` level with their reason, outages at `error`.

It applies the same scopes to everything behind it. Pipe several instances on
different paths, or use the next section, when requirements differ per operation.

## With acta/http

`acta/http` reads the scopes each operation requires from the OpenAPI contract and asks
an `Authenticator` whether a request has them. The two packages do not know each other;
this adapter, a dozen lines in your application, connects them:

```php
final readonly class BearerAuthenticator implements Authenticator
{
    public function __construct(private AccessTokenVerifier $verifier) {}

    public function authenticate(ServerRequestInterface $request, SecurityScheme $scheme, array $scopes): ServerRequestInterface
    {
        $token = BearerToken::fromRequest($request)
            ?? throw HttpException::unauthorized('A Bearer access token is required.', BearerChallenge::missingToken());

        try {
            $principal = $this->verifier->verify($token);
        } catch (InvalidToken $exception) {
            throw HttpException::unauthorized($exception->getMessage(), BearerChallenge::invalidToken($exception->getMessage()));
        }

        if ($principal->missingScopes($scopes) !== []) {
            throw HttpException::forbidden('The access token lacks a required scope.', BearerChallenge::insufficientScope($scopes));
        }

        return $request->withAttribute(Principal::class, $principal);
    }
}

new SecurityMiddleware($contract, ['bearer' => new BearerAuthenticator($verifier)]);
```

A `ProviderUnreachable` is left to propagate. Give it a status with an `ExceptionMapper`
of `acta/http`, typically 503, so that an outage of the provider is not reported as a
refusal.
