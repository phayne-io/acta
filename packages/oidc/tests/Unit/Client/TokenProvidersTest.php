<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Client;

use Acta\Oidc\Client\AuthenticatedHttpClient;
use Acta\Oidc\Client\ClientCredentialsTokenProvider;
use Acta\Oidc\Client\ClientSecretBasic;
use Acta\Oidc\Client\FileTokenStorage;
use Acta\Oidc\Client\InMemoryTokenStorage;
use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\StoredTokenProvider;
use Acta\Oidc\Client\TokenProvider;
use Acta\Oidc\Client\TokenSet;
use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\NoToken;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Tests\OidcTestCase;
use Acta\Oidc\Token\Claims;
use Closure;
use Laminas\Diactoros\Request;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\StreamFactory;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TokenProvidersTest extends OidcTestCase
{
    private OAuthClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->idp->registerClient('service', 'secret');
        $this->client = new OAuthClient('service', new ClientSecretBasic('secret'), $this->discovery, $this->transport, $this->jwtVerifier(), $this->clock);
    }

    public function testAClientTokenIsRequestedOnceThenRenewedBeforeItExpires(): void
    {
        $provider = new ClientCredentialsTokenProvider($this->client, ['accounts:read'], $this->cache, 30, clock: $this->clock);

        $first = $provider->accessToken();

        self::assertSame($first, $provider->accessToken());
        // Another instance sharing the cache does not ask for a token of its own.
        self::assertSame($first, new ClientCredentialsTokenProvider($this->client, ['accounts:read'], $this->cache, clock: $this->clock)->accessToken());
        self::assertSame(1, $this->idp->requestCount('/token'));

        // 30 seconds before the expiry, the token is no longer handed out.
        $this->clock->advance('+269 seconds');
        self::assertSame($first, $provider->accessToken());

        $this->clock->advance('+1 second');
        $second = $provider->accessToken();

        self::assertNotSame($first, $second);
        self::assertSame(2, $this->idp->requestCount('/token'));
    }

    public function testTokensAreCachedPerScopeAndParameters(): void
    {
        $read = new ClientCredentialsTokenProvider($this->client, ['accounts:read'], $this->cache, clock: $this->clock);
        $write = new ClientCredentialsTokenProvider($this->client, ['accounts:write'], $this->cache, clock: $this->clock);
        $other = new ClientCredentialsTokenProvider($this->client, ['accounts:read'], $this->cache, parameters: ['audience' => 'billing'], clock: $this->clock);

        self::assertNotSame($read->accessToken(), $write->accessToken());
        self::assertNotSame($read->accessToken(), $other->accessToken());
        self::assertSame(3, $this->idp->requestCount('/token'));
    }

    public function testAnInvalidatedClientTokenIsRequestedAgain(): void
    {
        $provider = new ClientCredentialsTokenProvider($this->client, cache: $this->cache, clock: $this->clock);
        $first = $provider->accessToken();

        $provider->invalidate();

        self::assertNotSame($first, $provider->accessToken());
    }

    public function testATokenWithoutKnownExpiryIsKeptByTheInstanceOnly(): void
    {
        $this->idp->tokenLifetime = null;
        $provider = new ClientCredentialsTokenProvider($this->client, cache: $this->cache, clock: $this->clock);

        $first = $provider->accessToken();
        $this->clock->advance('+1 day');

        self::assertSame($first, $provider->accessToken());
        self::assertNotSame($first, new ClientCredentialsTokenProvider($this->client, cache: $this->cache, clock: $this->clock)->accessToken());

        $provider->invalidate();

        self::assertNotSame($first, $provider->accessToken());
    }

    public function testAStoredUserTokenIsRefreshedTransparently(): void
    {
        $storage = new InMemoryTokenStorage($this->login());
        $provider = new StoredTokenProvider($this->client, $storage, 30, $this->clock);
        $first = $provider->accessToken();

        self::assertSame($first, $provider->accessToken());

        $this->clock->advance('+271 seconds');
        $second = $provider->accessToken();

        self::assertNotSame($first, $second);
        $stored = $storage->load();

        self::assertNotNull($stored);
        self::assertSame($second, $stored->accessToken);
        // The identity learnt at login is kept across refreshes.
        self::assertSame('ada', $stored->idTokenClaims?->string('sub'));

        $provider->invalidate();

        self::assertNotSame($second, $provider->accessToken());
    }

    public function testANewLoginIsNeededOnceTheSessionEnded(): void
    {
        $storage = new InMemoryTokenStorage();
        $provider = new StoredTokenProvider($this->client, $storage, clock: $this->clock);

        try {
            $provider->accessToken();
            self::fail('Nobody logged in.');
        } catch (NoToken $exception) {
            self::assertSame('No token is stored: log in first.', $exception->getMessage());
        }

        $provider->invalidate();

        // The provider ended the session: the refresh token no longer works.
        $storage->save($this->login());
        $this->idp->revokeRefreshTokens();
        $this->clock->advance('+10 minutes');

        try {
            $provider->accessToken();
            self::fail('The session ended.');
        } catch (NoToken $exception) {
            self::assertSame('The session has ended at the provider: log in again.', $exception->getMessage());
            self::assertNull($storage->load());
        }

        // An expired token without refresh token cannot be renewed either.
        $storage->save(new TokenSet('access-token', expiresAt: $this->clock->now()->modify('-1 second')));

        try {
            $provider->accessToken();
            self::fail('The token cannot be refreshed.');
        } catch (NoToken $exception) {
            self::assertSame('The access token has expired and cannot be refreshed: log in again.', $exception->getMessage());
            self::assertNull($storage->load());
        }

        $storage->save(new TokenSet('access-token'));
        $provider->invalidate();

        self::assertNull($storage->load());
    }

    public function testAnOutageDoesNotEndTheSession(): void
    {
        $storage = new InMemoryTokenStorage($this->login());
        $provider = new StoredTokenProvider($this->client, $storage, clock: $this->clock);
        $this->clock->advance('+10 minutes');
        $this->idp->available = false;

        try {
            $provider->accessToken();
            self::fail('The provider is down.');
        } catch (ProviderUnreachable) {
            self::assertNotNull($storage->load());
        }

        $this->idp->available = true;

        self::assertNotSame('', $provider->accessToken());
    }

    public function testTokensAreKeptInAFileReadableByItsOwnerOnly(): void
    {
        $directory = sys_get_temp_dir() . '/acta-oidc-' . bin2hex(random_bytes(6));
        $file = $directory . '/nested/tokens.json';
        $storage = new FileTokenStorage($file);
        $tokens = new TokenSet(
            'access-token',
            'Bearer',
            $this->clock->now()->modify('+5 minutes'),
            'refresh-token',
            'id.token',
            ['openid'],
            new Claims(['sub' => 'ada']),
        );

        try {
            self::assertNull($storage->load());

            $storage->save($tokens);

            self::assertEquals($tokens, $storage->load());
            self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
            self::assertSame([$file], glob($directory . '/nested/*'));

            $storage->save(new TokenSet('other'));

            self::assertEquals(new TokenSet('other'), new FileTokenStorage($file)->load());

            $storage->clear();
            $storage->clear();

            self::assertFileDoesNotExist($file);

            file_put_contents($file, 'not json');
            self::assertNull($storage->load());
            file_put_contents($file, '{"token_type":"Bearer"}');
            self::assertNull($storage->load());
        } finally {
            @unlink($file);
            @rmdir($directory . '/nested');
            @rmdir($directory);
        }
    }

    public function testAnUnwritableFileIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('cannot be');

        new FileTokenStorage('/proc/acta/tokens.json')->save(new TokenSet('access-token'));
    }

    public function testATokenSetSurvivesStorage(): void
    {
        $tokens = $this->login();

        self::assertEquals($tokens, TokenSet::fromArray($tokens->toArray()));
        self::assertEquals($tokens, TokenSet::fromArray((array) json_decode((string) json_encode($tokens->toArray()), true)));
        self::assertNull(TokenSet::fromArray([]));
        self::assertFalse(new TokenSet('a')->expiresWithin(3600, $this->clock->now()));
        self::assertTrue($tokens->expiresWithin(300, $this->clock->now()));
        self::assertFalse($tokens->expiresWithin(299, $this->clock->now()));
    }

    public function testRequestsAreSentWithABearerToken(): void
    {
        $service = self::service(static fn(RequestInterface $request): int => 200);
        $http = new AuthenticatedHttpClient($service, self::tokens('first', 'second'));

        $response = $http->sendRequest(new Request('https://billing.example.test/invoices', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Bearer first'], $service->authorizations);
    }

    public function testARefusedTokenIsRenewedAndTheRequestSentOnceMore(): void
    {
        $service = self::service(static fn(RequestInterface $request): int => $request->getHeaderLine('Authorization') === 'Bearer first' ? 401 : 201);
        $http = new AuthenticatedHttpClient($service, self::tokens('first', 'second'));
        $request = new Request('https://billing.example.test/invoices', 'POST', new StreamFactory()->createStream('{"amount":1}'));

        self::assertSame(201, $http->sendRequest($request)->getStatusCode());
        self::assertSame(['Bearer first', 'Bearer second'], $service->authorizations);
        self::assertSame(['{"amount":1}', '{"amount":1}'], $service->bodies);

        // A second refusal is returned as it is: no endless loop.
        $stubborn = self::service(static fn(RequestInterface $request): int => 401);

        self::assertSame(401, new AuthenticatedHttpClient($stubborn, self::tokens('first', 'second', 'third'))->sendRequest($request)->getStatusCode());
        self::assertSame(['Bearer first', 'Bearer second'], $stubborn->authorizations);
    }

    private function login(): TokenSet
    {
        $request = $this->client->authorizationRequest('https://app.example.test/callback', ['openid']);

        return $this->client->exchangeCode($request, $this->idp->authorize($request->url, 'ada'));
    }

    private static function tokens(string ...$tokens): TokenProvider
    {
        return new class (array_values($tokens)) implements TokenProvider {
            /**
             * @param list<string> $tokens
             */
            public function __construct(private array $tokens) {}

            #[Override]
            public function accessToken(): string
            {
                return $this->tokens[0];
            }

            #[Override]
            public function invalidate(): void
            {
                array_shift($this->tokens);
            }
        };
    }

    /**
     * @param Closure(RequestInterface): int $status
     *
     * @return ClientInterface&object{authorizations: list<string>, bodies: list<string>}
     */
    private static function service(Closure $status): ClientInterface
    {
        return new class ($status) implements ClientInterface {
            /** @var list<string> */
            public array $authorizations = [];

            /** @var list<string> */
            public array $bodies = [];

            /**
             * @param Closure(RequestInterface): int $status
             */
            public function __construct(private readonly Closure $status) {}

            #[Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->authorizations[] = $request->getHeaderLine('Authorization');
                $this->bodies[] = $request->getBody()->getContents();

                return new Response('php://memory', ($this->status)($request));
            }
        };
    }
}
