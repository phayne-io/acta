<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Client;

use Acta\Oidc\Client\AuthorizationRequest;
use Acta\Oidc\Client\ClientAuthentication;
use Acta\Oidc\Client\ClientSecretBasic;
use Acta\Oidc\Client\ClientSecretPost;
use Acta\Oidc\Client\DeviceAuthorization;
use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\Pkce;
use Acta\Oidc\Client\PrivateKeyJwt;
use Acta\Oidc\Client\PublicClient;
use Acta\Oidc\Client\TokenSet;
use Acta\Oidc\Discovery\ProviderMetadata;
use Acta\Oidc\Exception\AuthorizationFailed;
use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Exception\ProviderError;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Jose\KeyFactory;
use Acta\Oidc\Testing\FakeIdentityProvider;
use Acta\Oidc\Tests\OidcTestCase;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\Claims;
use Acta\Oidc\Token\TokenError;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\StreamFactory;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OAuthClientTest extends OidcTestCase
{
    private const string REDIRECT_URI = 'https://app.example.test/callback';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->idp->registerClient('service', 'top secret & more');
        $this->idp->registerClient('cli');
        $this->idp->setUser('ada', ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    }

    public function testClientCredentialsObtainATokenForTheClientItself(): void
    {
        $tokens = $this->client()->clientCredentials(['accounts:read', 'accounts:write']);

        self::assertSame('Bearer', $tokens->tokenType);
        self::assertSame(['accounts:read', 'accounts:write'], $tokens->scopes);
        self::assertEquals($this->clock->now()->modify('+300 seconds'), $tokens->expiresAt);
        self::assertNull($tokens->refreshToken);
        self::assertNull($tokens->idToken);

        // The token is one a resource server accepts.
        $principal = new AccessTokenVerifier($this->jwtVerifier(), 'api')->verify($tokens->accessToken);

        self::assertSame('service', $principal->subject);
        self::assertSame('service', $principal->clientId);
        self::assertTrue($principal->hasScope('accounts:write'));
    }

    public function testAdditionalParametersAreSentToTheTokenEndpoint(): void
    {
        $tokens = $this->client()->clientCredentials(parameters: ['audience' => 'https://api.example.test']);
        $sent = array_last($this->idp->requests);
        self::assertNotNull($sent);
        parse_str((string) $sent->getBody(), $form);

        self::assertSame(['audience' => 'https://api.example.test', 'grant_type' => 'client_credentials'], $form);
        self::assertSame([], $tokens->scopes);
        self::assertSame(self::ISSUER, $this->client()->issuer());
    }

    /**
     * @param array<string, mixed>|null $publicJwk
     */
    #[DataProvider('authenticationMethods')]
    public function testTheClientAuthenticatesWithEveryMethod(ClientAuthentication $authentication, ?string $secret, ?array $publicJwk): void
    {
        $this->idp->registerClient('confidential', $secret, $publicJwk);

        self::assertNotSame('', $this->client('confidential', $authentication)->clientCredentials()->accessToken);
    }

    /**
     * @return iterable<string, array{ClientAuthentication, string|null, array<string, mixed>|null}>
     */
    public static function authenticationMethods(): iterable
    {
        $key = KeyFactory::generate('ES256', ['kid' => 'client-key']);

        yield 'client_secret_basic' => [new ClientSecretBasic('s3cr3t:with/odd chars+'), 's3cr3t:with/odd chars+', null];
        yield 'client_secret_post' => [new ClientSecretPost('s3cr3t'), 's3cr3t', null];
        yield 'private_key_jwt' => [new PrivateKeyJwt($key, 'ES256'), null, KeyFactory::toPublic($key)];
        yield 'private_key_jwt addressed to the issuer' => [new PrivateKeyJwt($key, 'ES256', issuerAsAudience: true), null, KeyFactory::toPublic($key)];
    }

    public function testWrongCredentialsAreRefusedByTheProvider(): void
    {
        $other = KeyFactory::generate('ES256');
        $this->idp->registerClient('keyed', null, KeyFactory::toPublic(KeyFactory::generate('ES256')));

        foreach ([
            $this->client('service', new ClientSecretBasic('wrong')),
            $this->client('service', new ClientSecretPost('wrong')),
            $this->client('unknown', new ClientSecretPost('secret')),
            $this->client('keyed', new PrivateKeyJwt($other, 'ES256')),
            $this->client('keyed', new PublicClient()),
        ] as $client) {
            try {
                (void) $client->clientCredentials();
                self::fail('The client should be refused.');
            } catch (ProviderError $error) {
                self::assertSame('invalid_client', $error->error);
                self::assertSame(401, $error->status);
            }
        }
    }

    public function testAPrivateKeyAssertionIsShortLivedAndUnique(): void
    {
        $key = KeyFactory::generate('ES256', ['kid' => 'client-key']);
        $authentication = new PrivateKeyJwt($key, 'ES256', lifetime: 45, clock: $this->clock);
        $metadata = new ProviderMetadata(self::ISSUER, tokenEndpoint: self::ISSUER . '/token');

        [$form, $headers] = $authentication->authenticate('web', ['grant_type' => 'client_credentials'], $metadata);
        [$again] = $authentication->authenticate('web', [], $metadata);
        $decode = static fn(string $jwt, int $part): array => (array) json_decode((string) base64_decode(strtr(explode('.', $jwt)[$part], '-_', '+/')), true);
        $claims = $decode($form['client_assertion'], 1);
        $now = $this->clock->now()->getTimestamp();

        self::assertSame([], $headers);
        self::assertSame('client_credentials', $form['grant_type']);
        self::assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
        self::assertSame(['typ' => 'JWT', 'kid' => 'client-key', 'alg' => 'ES256'], $decode($form['client_assertion'], 0));
        self::assertSame(['web', 'web', self::ISSUER . '/token', $now, $now, $now + 45], [$claims['iss'], $claims['sub'], $claims['aud'], $claims['iat'], $claims['nbf'], $claims['exp']]);
        self::assertNotSame($claims['jti'], $decode($again['client_assertion'], 1)['jti']);
    }

    public function testAPrivateKeyIsReadFromPem(): void
    {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($resource);
        openssl_pkey_export($resource, $pem);
        self::assertIsString($pem);
        $this->idp->registerClient('keyed', null, KeyFactory::toPublic(KeyFactory::fromPem($pem)));

        self::assertNotSame('', $this->client('keyed', PrivateKeyJwt::fromPem($pem, 'RS256', 'client-key'))->clientCredentials()->accessToken);
    }

    public function testALoginGoesThroughTheAuthorizationCodeFlowWithPkce(): void
    {
        $client = $this->client();
        $request = $client->authorizationRequest(self::REDIRECT_URI, ['openid', 'profile'], ['prompt' => 'login']);
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith(self::ISSUER . '/authorize?', $request->url);
        self::assertSame(
            [
                'prompt' => 'login',
                'response_type' => 'code',
                'client_id' => 'service',
                'redirect_uri' => self::REDIRECT_URI,
                'scope' => 'openid profile',
                'state' => $request->state,
                'nonce' => $request->nonce,
                'code_challenge' => Pkce::challenge($request->codeVerifier),
                'code_challenge_method' => 'S256',
            ],
            $query,
        );
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $request->codeVerifier);
        self::assertNotSame($request->state, $client->authorizationRequest(self::REDIRECT_URI)->state);

        // The request survives the session between the redirect and the callback.
        $restored = AuthorizationRequest::fromArray($request->toArray());
        self::assertNotNull($restored);
        self::assertEquals($request, $restored);

        $tokens = $client->exchangeCode($restored, $this->idp->authorize($request->url, 'ada'));

        self::assertNotNull($tokens->refreshToken);
        self::assertNotNull($tokens->idToken);
        self::assertSame(['openid', 'profile'], $tokens->scopes);
        self::assertSame('ada', $tokens->idTokenClaims?->string('sub'));
        self::assertSame('Ada Lovelace', $tokens->idTokenClaims->string('name'));
        self::assertSame($request->nonce, $tokens->idTokenClaims->string('nonce'));
        self::assertSame('ada', new AccessTokenVerifier($this->jwtVerifier(), 'api')->verify($tokens->accessToken)->subject);
    }

    public function testWithoutTheOpenidScopeNoIdTokenIsExpected(): void
    {
        $client = $this->client('cli', new PublicClient());
        $request = $client->authorizationRequest(self::REDIRECT_URI, ['accounts:read']);

        self::assertNull($request->nonce);
        self::assertStringNotContainsString('nonce=', $request->url);

        $tokens = $client->exchangeCode($request, $this->idp->authorize($request->url, 'ada'));

        self::assertNull($tokens->idToken);
        self::assertNull($tokens->idTokenClaims);
    }

    public function testACallbackThatDoesNotAnswerTheRequestIsRefused(): void
    {
        $client = $this->client();
        $request = $client->authorizationRequest(self::REDIRECT_URI);
        $callback = $this->idp->authorize($request->url, 'ada');

        foreach ([
            'another state' => [[...$callback, 'state' => 'forged'], 'invalid_callback: The callback does not belong to this login.'],
            'no state' => [array_diff_key($callback, ['state' => true]), 'invalid_callback: The callback does not belong to this login.'],
            'another provider' => [[...$callback, 'iss' => 'https://evil.example.test'], 'invalid_callback: The callback comes from another provider.'],
            'no code' => [array_diff_key($callback, ['code' => true]), 'invalid_callback: The callback carries no authorization code.'],
            'refusal of the user' => [['state' => $request->state, 'error' => 'access_denied', 'error_description' => 'The user said no.'], 'access_denied: The user said no.'],
            'error with a forged state' => [['state' => 'forged', 'error' => 'access_denied'], 'invalid_callback: The callback does not belong to this login.'],
        ] as $case => [$parameters, $message]) {
            try {
                (void) $client->exchangeCode($request, $parameters);
                self::fail($case . ' should be refused.');
            } catch (AuthorizationFailed $failure) {
                self::assertSame($message, $failure->getMessage(), $case);
            }
        }

        // None of these attempts reached the token endpoint.
        self::assertSame(0, $this->idp->requestCount('/token'));
    }

    public function testACodeCannotBeRedeemedWithoutItsVerifierOrTwice(): void
    {
        $client = $this->client();
        $request = $client->authorizationRequest(self::REDIRECT_URI);
        $stolen = new AuthorizationRequest($request->url, $request->state, $request->nonce, Pkce::verifier(), self::REDIRECT_URI);
        $callback = $this->idp->authorize($request->url, 'ada');

        try {
            (void) $client->exchangeCode($stolen, $callback);
            self::fail('The code should not be redeemed without its verifier.');
        } catch (ProviderError $error) {
            self::assertSame('invalid_grant: The code verifier does not match.', $error->getMessage());
        }

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('invalid_grant: The authorization code is not valid.');

        (void) $client->exchangeCode($request, $callback);
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('refusedIdTokens')]
    public function testAnIdTokenIsVerifiedBeforeBeingTrusted(array $claims, TokenError $reason): void
    {
        $tokens = new TokenSet('access-token', idToken: $this->idp->sign([
            'iss' => self::ISSUER,
            'sub' => 'ada',
            'aud' => 'service',
            'exp' => $this->clock->now()->getTimestamp() + 300,
            ...$claims,
        ]));
        $idp = $this->idp;
        $client = new OAuthClient('service', new ClientSecretBasic('top secret & more'), $this->discovery, new Transport(
            new class ($idp, $tokens) implements ClientInterface {
                public function __construct(private readonly FakeIdentityProvider $idp, private readonly TokenSet $tokens) {}

                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    // A provider, or someone in the middle, answering the token request with a crafted ID token.
                    return str_ends_with($request->getUri()->getPath(), '/token')
                        ? new JsonResponse(['access_token' => $this->tokens->accessToken, 'id_token' => $this->tokens->idToken])
                        : $this->idp->sendRequest($request);
                }
            },
            new RequestFactory(),
            new StreamFactory(),
        ), clock: $this->clock);
        $request = new AuthorizationRequest('https://idp.example.test/authorize', 'state', 'expected-nonce', 'verifier', self::REDIRECT_URI);

        try {
            (void) $client->exchangeCode($request, ['state' => 'state', 'code' => 'code']);
            self::fail('The ID token should be refused.');
        } catch (InvalidToken $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, TokenError}>
     */
    public static function refusedIdTokens(): iterable
    {
        yield 'issued to another client' => [['aud' => 'other', 'nonce' => 'expected-nonce'], TokenError::WrongAudience];
        yield 'several audiences without authorized party' => [['aud' => ['service', 'other'], 'nonce' => 'expected-nonce'], TokenError::WrongParty];
        yield 'another authorized party' => [['aud' => ['service', 'other'], 'azp' => 'other', 'nonce' => 'expected-nonce'], TokenError::WrongParty];
        yield 'no subject' => [['sub' => null, 'nonce' => 'expected-nonce'], TokenError::MissingClaim];
        yield 'another nonce' => [['nonce' => 'replayed'], TokenError::WrongNonce];
        yield 'no nonce' => [[], TokenError::WrongNonce];
        yield 'hash of another access token' => [['nonce' => 'expected-nonce', 'at_hash' => 'AAAAAAAAAAAAAAAAAAAAAA'], TokenError::WrongHash];
        yield 'expired' => [['nonce' => 'expected-nonce', 'exp' => 1], TokenError::Expired];
        yield 'another issuer' => [['nonce' => 'expected-nonce', 'iss' => 'https://evil.example.test'], TokenError::WrongIssuer];
    }

    public function testAMissingIdTokenIsRefusedWhenOneWasAskedFor(): void
    {
        $client = $this->client('cli', new PublicClient());
        $request = $client->authorizationRequest(self::REDIRECT_URI, ['accounts:read']);
        $callback = $this->idp->authorize($request->url, 'ada');
        // The client believes it asked for an ID token; the provider issued none for these scopes.
        $expectingIdToken = new AuthorizationRequest($request->url, $request->state, 'nonce', $request->codeVerifier, self::REDIRECT_URI);

        $this->expectException(InvalidToken::class);
        $this->expectExceptionMessage('did not return the ID token');

        (void) $client->exchangeCode($expectingIdToken, $callback);
    }

    public function testTokensAreRefreshed(): void
    {
        $client = $this->client();
        $tokens = $this->login($client);
        $this->clock->advance('+4 minutes');

        $refreshed = $client->refresh($tokens);

        self::assertNotSame($tokens->accessToken, $refreshed->accessToken);
        // The provider did not rotate the refresh token: it is kept.
        self::assertSame($tokens->refreshToken, $refreshed->refreshToken);
        self::assertEquals($this->clock->now()->modify('+300 seconds'), $refreshed->expiresAt);

        $this->idp->rotateRefreshTokens = true;
        $rotated = $client->refresh((string) $tokens->refreshToken, ['openid']);

        self::assertNotSame($tokens->refreshToken, $rotated->refreshToken);
        self::assertSame(['openid'], $rotated->scopes);

        try {
            (void) $client->refresh($tokens);
            self::fail('The retired refresh token should be refused.');
        } catch (ProviderError $error) {
            self::assertSame('invalid_grant', $error->error);
        }

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('has no refresh token');

        (void) $client->refresh(new TokenSet('access-token'));
    }

    public function testUserInfoDescribesTheUserWhoLoggedIn(): void
    {
        $client = $this->client();
        $tokens = $this->login($client);

        $claims = $client->userInfo($tokens);

        self::assertSame(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sub' => 'ada'], $claims->values);
        self::assertSame('ada', $client->userInfo($tokens->accessToken)->string('sub'));

        // The ID token says "ada"; a UserInfo response about someone else must not be merged with it.
        $mismatched = $tokens->withIdTokenClaims(new Claims(['sub' => 'grace']));

        try {
            (void) $client->userInfo($mismatched);
            self::fail('The response should be refused.');
        } catch (InvalidToken $exception) {
            self::assertSame(TokenError::WrongSubject, $exception->reason);
        }

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('invalid_token');

        (void) $client->userInfo('not-a-token');
    }

    public function testADeviceIsAuthorizedFromAnotherDevice(): void
    {
        $client = $this->client('cli', new PublicClient());
        $device = $client->requestDeviceAuthorization(['openid', 'accounts:read']);

        self::assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $device->userCode);
        self::assertSame(self::ISSUER . '/activate', $device->verificationUri);
        self::assertSame(self::ISSUER . '/activate?user_code=' . $device->userCode, $device->verificationUriComplete);
        self::assertEquals($this->clock->now()->modify('+600 seconds'), $device->expiresAt);
        self::assertSame(5, $device->interval);

        // Nothing happened yet: the same authorization comes back.
        self::assertSame($device, $client->checkDeviceAuthorization($device));

        $waits = [];
        $tokens = $client->pollDeviceToken($device, function (int $seconds) use (&$waits, $device): void {
            $waits[] = $seconds;
            $this->clock->advance("+{$seconds} seconds");

            match (count($waits)) {
                2 => $this->idp->askDevicesToSlowDown(),
                4 => $this->idp->approveDevice($device->userCode, 'ada'),
                default => null,
            };
        });

        // Pending, then asked to slow down, pending again at the slower pace, then approved.
        self::assertSame([5, 5, 10, 10], $waits);
        self::assertSame('ada', $tokens->idTokenClaims?->string('sub'));
        self::assertNotNull($tokens->refreshToken);
        self::assertSame(['openid', 'accounts:read'], $tokens->scopes);
    }

    public function testADeviceAuthorizationCanBeRefusedOrExpire(): void
    {
        $client = $this->client('cli', new PublicClient());
        $noWait = static fn(int $seconds): null => null;

        $refused = $client->requestDeviceAuthorization();
        $this->idp->denyDevice($refused->userCode);

        try {
            (void) $client->pollDeviceToken($refused, $noWait);
            self::fail('The refusal should be reported.');
        } catch (AuthorizationFailed $failure) {
            self::assertSame('access_denied', $failure->error);
        }

        // The provider considers the codes expired.
        $expired = $client->requestDeviceAuthorization();
        $this->clock->advance('+599 seconds');

        try {
            (void) $client->pollDeviceToken($expired, fn(int $seconds) => $this->clock->advance('+1 second'));
            self::fail('The expiry should be reported.');
        } catch (AuthorizationFailed $failure) {
            self::assertSame('expired_token', $failure->error);
        }

        // The client itself gives up once the codes have expired, without asking.
        $requests = count($this->idp->requests);
        $late = new DeviceAuthorization('device', 'CODE', self::ISSUER . '/activate', null, $this->clock->now());

        try {
            (void) $client->pollDeviceToken($late, $noWait);
            self::fail('The expiry should be reported.');
        } catch (AuthorizationFailed $failure) {
            self::assertSame('expired_token: The device authorization was not completed in time.', $failure->getMessage());
            self::assertCount($requests, $this->idp->requests);
        }

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('invalid_grant');

        (void) $client->checkDeviceAuthorization(new DeviceAuthorization('unknown', 'CODE', self::ISSUER . '/activate', null, $this->clock->now()->modify('+1 minute')));
    }

    public function testTheLogoutUrlEndsTheSessionAtTheProvider(): void
    {
        $client = $this->client();

        self::assertSame(self::ISSUER . '/logout?client_id=service', $client->logoutUrl());
        self::assertSame(
            self::ISSUER . '/logout?client_id=service&id_token_hint=id.token&post_logout_redirect_uri=https%3A%2F%2Fapp.example.test%2Fbye&state=xyz',
            $client->logoutUrl('id.token', 'https://app.example.test/bye', 'xyz'),
        );
    }

    public function testEndpointsTheProviderLacksAreConfigurationErrors(): void
    {
        $metadata = new ProviderMetadata(self::ISSUER, authorizationEndpoint: self::ISSUER . '/authorize?tenant=acme');
        $client = new OAuthClient('service', new PublicClient(), $metadata, $this->transport);

        self::assertStringStartsWith(self::ISSUER . '/authorize?tenant=acme&response_type=code', $client->authorizationRequest(self::REDIRECT_URI, [])->url);

        foreach ([
            static fn() => $client->clientCredentials(),
            static fn() => $client->requestDeviceAuthorization(),
            static fn() => $client->userInfo('token'),
            static fn() => $client->logoutUrl(),
        ] as $call) {
            try {
                $call();
                self::fail('The missing endpoint should be reported.');
            } catch (ConfigurationError $error) {
                self::assertStringContainsString('does not advertise its', $error->getMessage());
            }
        }
    }

    public function testATokenResponseWithoutAccessTokenIsUnusable(): void
    {
        $this->expectException(ProviderUnreachable::class);
        $this->expectExceptionMessage('without access token');

        TokenSet::fromResponse(['token_type' => 'Bearer'], $this->clock->now());
    }

    private function client(string $clientId = 'service', ?ClientAuthentication $authentication = null): OAuthClient
    {
        return new OAuthClient(
            $clientId,
            $authentication ?? new ClientSecretBasic('top secret & more'),
            $this->discovery,
            $this->transport,
            $this->jwtVerifier(),
            $this->clock,
        );
    }

    private function login(OAuthClient $client): TokenSet
    {
        $request = $client->authorizationRequest(self::REDIRECT_URI, ['openid', 'profile']);

        return $client->exchangeCode($request, $this->idp->authorize($request->url, 'ada'));
    }
}
