# Client

```php
$client = new OAuthClient(
    'billing-service',                 // client identifier
    new ClientSecretBasic($secret),    // how the client authenticates
    $provider,                         // Discovery, or ProviderMetadata
    $transport,
);
```

| Need | Grant | Methods |
| --- | --- | --- |
| A service calling another service | Client credentials | `clientCredentials()` |
| A user in a browser | Authorization code with PKCE | `authorizationRequest()`, `exchangeCode()` |
| A user at a terminal or on a device without browser | Device authorization | `requestDeviceAuthorization()`, `pollDeviceToken()` |
| Staying logged in | Refresh token | `refresh()` |

The implicit and password grants are not offered: both are left out of OAuth 2.1.

## Client authentication

| Class | Method | Use it when |
| --- | --- | --- |
| `ClientSecretBasic` | `client_secret_basic` | The client has a secret; the default of most providers |
| `ClientSecretPost` | `client_secret_post` | The provider only accepts the secret in the form |
| `PrivateKeyJwt` | `private_key_jwt` | You would rather not share a secret: the provider only holds your public key |
| `PublicClient` | none | The client cannot keep a secret: command-line tools, native and single-page applications |

```php
$authentication = PrivateKeyJwt::fromPem(file_get_contents('client.key'), 'RS256', keyId: 'client-key-1');
```

The assertion is addressed to the token endpoint. Pass `issuerAsAudience: true` to
`new PrivateKeyJwt(...)` for providers following the most recent profiles, which expect
the issuer instead.

## Client credentials

```php
$tokens = $client->clientCredentials(['accounts:read'], ['audience' => 'https://api.example.com']);
```

The second argument carries the extra parameters some providers need, such as
`audience` (Auth0) or `resource`.

Rather than calling it yourself, let a token provider cache and renew the token, and a
decorated PSR-18 client add it to requests:

```php
$tokens = new ClientCredentialsTokenProvider($client, ['accounts:read'], $cache, margin: 30);
$http = new AuthenticatedHttpClient($httpClient, $tokens);
```

`margin` is how long before its expiry a token stops being handed out, so that it does
not expire on its way. A 401 from the service invalidates the token and the request is
sent once more.

## Authorization code with PKCE

```php
$login = $client->authorizationRequest(
    'https://app.example.com/callback',
    ['openid', 'profile', 'offline_access'],
    ['prompt' => 'login'],
);

$_SESSION['login'] = $login->toArray();
// redirect to $login->url
```

Each request gets its own `state`, PKCE verifier (S256) and, with the `openid` scope,
`nonce`. On the callback:

```php
$login = AuthorizationRequest::fromArray($_SESSION['login']);
unset($_SESSION['login']);

$tokens = $client->exchangeCode($login, $request->getQueryParams());
```

`exchangeCode()` refuses the callback with `AuthorizationFailed` when the `state` is
not the one of this login, when the user refused (`$failure->error` is then
`access_denied`), or when the `iss` parameter names another provider. Only then is the
code exchanged, with its verifier.

## ID tokens

An ID token is never returned unverified. Besides what every JWT goes through, it must
be issued for this client (`aud`, and `azp` when present), contain the `nonce` of the
login, name a subject, and its `at_hash`, when present, must match the access token.

```php
$tokens->idTokenClaims->string('sub');
$tokens->idTokenClaims->string('email');
```

`userInfo()` asks the provider for the claims of the user. Given the token set of a
login, it checks that the answer describes the same subject as the ID token.

```php
$client->logoutUrl($tokens->idToken, 'https://app.example.com/', $state);   // RP-initiated logout
```

## Device authorization

```php
$device = $client->requestDeviceAuthorization(['openid', 'accounts:read']);

echo "Open {$device->verificationUri} and enter {$device->userCode}\n";

$tokens = $client->pollDeviceToken($device);
```

`pollDeviceToken()` waits at the pace the provider asks for, slows down when told to,
and throws `AuthorizationFailed` if the user refuses or the code expires. In an
application that cannot block, call `checkDeviceAuthorization()` from your own loop: it
returns the tokens, or the authorization to keep polling with.

## Staying logged in

```php
$storage = new FileTokenStorage($home . '/.config/bank/tokens.json');   // readable by its owner only
$storage->save($tokens);

$provider = new StoredTokenProvider($client, $storage);
$provider->accessToken();   // refreshed when needed, and saved
```

Once the provider ends the session, `accessToken()` clears the storage and throws
`NoToken`: time to log in again. An outage of the provider does not end the session.

`TokenStorage` is an interface: implement it over the session of a web application or
the keychain of the operating system. A stored token set holds secrets.

## Errors

| Exception | Meaning |
| --- | --- |
| `ProviderError` | The provider answered with an OAuth error; `$error->error` holds its code, such as `invalid_grant` |
| `ProviderUnreachable` | Network failure, or an answer that is not usable |
| `AuthorizationFailed` | A login did not complete |
| `InvalidToken` | A token was refused; `$exception->reason` says why |
| `NoToken` | Nothing stored, or the session ended |
| `ConfigurationError` | The provider lacks an endpoint, a key cannot be read: to fix before deploying |

All implement `OidcException`.
