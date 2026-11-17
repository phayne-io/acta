# Testing

`Acta\Oidc\Testing\FakeIdentityProvider` is an identity provider living in memory. It
is a PSR-18 client: pass it wherever your application expects the HTTP client reaching
the provider, and no request leaves the process.

```php
$idp = new FakeIdentityProvider($responseFactory, $streamFactory, 'https://idp.example.test', clock: $clock);
$transport = new Transport($idp, $requestFactory, $streamFactory);
```

It serves a discovery document, a JWKS, a token endpoint, a device authorization
endpoint and a UserInfo endpoint, and signs real tokens with keys it generates. It
checks what a real provider checks, client credentials, PKCE and redirect URIs
included, so a passing test means something.

## Tokens for a protected API

```php
$token = $idp->issueAccessToken(['sub' => 'ada', 'scope' => 'accounts:write']);

$response = $app->handle($request->withHeader('Authorization', 'Bearer ' . $token));
```

The defaults make a valid token: issued by the provider for `user-1` and the audience
`api`, for five minutes. Override claims and header members, or set one to `null` to
remove it, to test refusals:

```php
$idp->issueAccessToken(['exp' => time() - 60]);                    // expired
$idp->issueAccessToken(['aud' => 'another-api']);                  // meant for someone else
$idp->issueAccessToken([], ['typ' => null]);                       // untyped
$idp->sign($claims, jwk: KeyFactory::generate('ES256'));           // forged with a foreign key
```

## Logins

```php
$idp->registerClient('web', 'secret');                 // confidential
$idp->registerClient('cli');                           // public
$idp->registerClient('service', publicJwk: $jwk);      // private_key_jwt
$idp->setUser('ada', ['name' => 'Ada Lovelace']);

// Authorization code: plays the user logging in, returns the callback parameters.
$login = $client->authorizationRequest('https://app.example.test/callback');
$tokens = $client->exchangeCode($login, $idp->authorize($login->url, subject: 'ada'));

// Device authorization: plays the user approving on another device.
$device = $client->requestDeviceAuthorization();
$idp->approveDevice($device->userCode, subject: 'ada');   // or denyDevice()
```

## What goes wrong in production

```php
$idp->rotateKeys();                      // tokens are now signed with a new key
$idp->rotateKeys(keepPrevious: true);    // the previous key stays published
$idp->available = false;                 // outage
$idp->revokeRefreshTokens();             // every session ended
$idp->rotateRefreshTokens = true;        // refresh tokens are single-use
$idp->tokenLifetime = null;              // token responses without expires_in
$idp->askDevicesToSlowDown();
```

## What the application asked

```php
$idp->requestCount('/token');   // was the token cached?
$idp->requestCount('/jwks');
$idp->requests;                 // every PSR-7 request received
```

Give the same clock to the provider and to your application to travel in time: tokens
expire, caches empty, and nothing sleeps.
