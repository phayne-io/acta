<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Server;

use Acta\Oidc\Server\BearerAuthMiddleware;
use Acta\Oidc\Server\BearerChallenge;
use Acta\Oidc\Server\BearerToken;
use Acta\Oidc\Tests\Fixture\RecordingLogger;
use Acta\Oidc\Tests\OidcTestCase;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\Principal;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerAuthTest extends OidcTestCase
{
    private RecordingLogger $logger;

    private ?Principal $seen = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
    }

    public function testAValidTokenReachesTheHandlerWithItsPrincipal(): void
    {
        $token = $this->idp->issueAccessToken(['sub' => 'ada', 'scope' => 'accounts:read accounts:write']);

        $response = $this->handle(['Authorization' => 'Bearer ' . $token], ['accounts:write']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('ada', $this->seen?->subject);
        self::assertSame([], $this->logger->records);
    }

    public function testARequestWithoutTokenIsChallengedWithoutError(): void
    {
        foreach ([[], ['Authorization' => 'Basic YWRhOnNlY3JldA=='], ['Authorization' => 'Bearer']] as $headers) {
            $response = $this->handle($headers);

            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
            self::assertSame(
                ['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'detail' => 'A Bearer access token is required.'],
                self::json($response),
            );
        }

        self::assertNull($this->seen);
    }

    public function testARefusedTokenIsAnInvalidTokenError(): void
    {
        $expired = $this->idp->issueAccessToken(['exp' => $this->clock->now()->getTimestamp() - 60]);

        $response = $this->handle(['Authorization' => 'Bearer ' . $expired], realm: 'banking');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'Bearer realm="banking", error="invalid_token", error_description="The token has expired."',
            $response->getHeaderLine('WWW-Authenticate'),
        );
        self::assertSame('The token has expired.', self::json($response)['detail']);
        self::assertNull($this->seen);
        self::assertSame(['info'], $this->logger->levels());
        self::assertSame('expired', $this->logger->records[0]['context']['reason']);
    }

    public function testAMissingScopeIsForbidden(): void
    {
        $token = $this->idp->issueAccessToken(['scope' => 'accounts:read']);

        $response = $this->handle(['Authorization' => 'Bearer ' . $token], ['accounts:read', 'accounts:write']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'Bearer error="insufficient_scope", scope="accounts:read accounts:write"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
        self::assertSame('Forbidden', self::json($response)['title']);
        self::assertNull($this->seen);
        self::assertSame('accounts:write', $this->logger->records[0]['context']['scopes']);
    }

    public function testAnOutageOfTheProviderIsAServiceUnavailable(): void
    {
        $token = $this->idp->issueAccessToken();
        $this->idp->available = false;

        $response = $this->handle(['Authorization' => 'Bearer ' . $token]);

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($response->hasHeader('WWW-Authenticate'));
        self::assertSame('Access tokens cannot be verified at the moment.', self::json($response)['detail']);
        self::assertSame(['error'], $this->logger->levels());
    }

    #[DataProvider('authorizationHeaders')]
    public function testTheTokenIsReadFromTheAuthorizationHeader(string $header, ?string $token): void
    {
        self::assertSame($token, BearerToken::fromRequest(self::serverRequest($header === '' ? [] : ['Authorization' => $header])));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function authorizationHeaders(): iterable
    {
        yield 'bearer' => ['Bearer abc.def-ghi_jkl', 'abc.def-ghi_jkl'];
        yield 'case-insensitive scheme' => ['bearer abc', 'abc'];
        yield 'several spaces' => ['Bearer   abc', 'abc'];
        yield 'padded base64' => ['Bearer YWJj+/==', 'YWJj+/=='];
        yield 'surrounding spaces' => ['  Bearer abc  ', 'abc'];
        yield 'none' => ['', null];
        yield 'another scheme' => ['Basic YWJj', null];
        yield 'no token' => ['Bearer ', null];
        yield 'two tokens' => ['Bearer abc def', null];
        yield 'forbidden characters' => ['Bearer abc"def', null];
    }

    public function testChallengesFollowRfc6750(): void
    {
        self::assertSame('Bearer', BearerChallenge::missingToken());
        self::assertSame('Bearer realm="banking"', BearerChallenge::missingToken('banking'));
        self::assertSame('Bearer error="invalid_token", error_description="Expired."', BearerChallenge::invalidToken('Expired.'));
        self::assertSame('Bearer error="insufficient_scope", scope="a b"', BearerChallenge::insufficientScope(['a', 'b']));
        // Characters that would break out of the quoted string are removed.
        self::assertSame(
            'Bearer error="invalid_token", error_description="Bad , error=other token"',
            BearerChallenge::invalidToken("Bad \", error=\"other\" \\token\r\n"),
        );
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @param list<string>                    $scopes
     */
    private function handle(array $headers, array $scopes = [], ?string $realm = null): ResponseInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public ?Principal $seen = null;

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = Principal::from($request);

                return new ResponseFactory()->createResponse(204);
            }
        };
        $middleware = new BearerAuthMiddleware(
            new AccessTokenVerifier($this->jwtVerifier(), 'api'),
            new ResponseFactory(),
            new StreamFactory(),
            $scopes,
            $realm,
            $this->logger,
        );

        $response = $middleware->process(self::serverRequest($headers), $handler);
        $this->seen = $handler->seen;

        return $response;
    }
}
