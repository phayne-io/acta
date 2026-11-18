<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Security;

use Acta\Http\Exception\ContractError;
use Acta\Http\Exception\HttpException;
use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Handler\OperationHandlers;
use Acta\Http\OpenApi\Contract;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Security\Authenticator;
use Acta\Http\Security\SecurityMiddleware;
use Acta\Http\Testing\Pipeline;
use Acta\Http\Tests\Fixture\TokenAuthenticator;
use Acta\Http\Tests\HttpTestCase;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use stdClass;

final class SecurityMiddlewareTest extends HttpTestCase
{
    private TokenAuthenticator $bearer;

    private TokenAuthenticator $apiKey;

    /** @var array<string, mixed> attributes of the request that reached the handler */
    private array $attributes = [];

    #[Override]
    protected function setUp(): void
    {
        $this->bearer = new TokenAuthenticator('Authorization', [
            'Bearer reader' => ['accounts:read'],
            'Bearer writer' => ['accounts:read', 'accounts:write'],
        ]);
        $this->apiKey = new TokenAuthenticator('X-Api-Key', ['secret' => []]);
    }

    public function testAPublicOperationNeedsNoCredentials(): void
    {
        $this->handle(self::request('GET', '/accounts/search?q=ada'));

        self::assertSame([], $this->bearer->calls);
        self::assertSame([], $this->apiKey->calls);
    }

    public function testTheAuthenticatorReceivesTheSchemeAndTheScopesOfTheOperation(): void
    {
        $this->handle(self::request('POST', '/accounts', ['Authorization' => 'Bearer writer']));

        self::assertSame([['bearer', ['accounts:write']]], $this->bearer->calls);
        self::assertSame('Bearer writer', $this->attributes['subject:bearer']);
    }

    public function testTheRequirementsOfTheDocumentApplyByDefault(): void
    {
        $this->handle(self::request('DELETE', '/accounts/' . self::ACCOUNT_ID, ['Authorization' => 'Bearer reader']));

        self::assertSame([['bearer', []]], $this->bearer->calls);
    }

    public function testMissingCredentialsAreUnauthorized(): void
    {
        $exception = $this->failure(self::request('POST', '/accounts'));

        self::assertSame(401, $exception->status);
        self::assertSame('Credentials are required.', $exception->getMessage());
        self::assertSame(['WWW-Authenticate' => 'Bearer error="invalid_token"'], $exception->headers);
    }

    public function testInsufficientScopesAreForbidden(): void
    {
        $exception = $this->failure(self::request('POST', '/accounts', ['Authorization' => 'Bearer reader']));

        self::assertSame(403, $exception->status);
        self::assertSame('Missing scope: accounts:write.', $exception->getMessage());
        self::assertSame(['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="accounts:write"'], $exception->headers);
    }

    public function testAnyAlternativeIsEnough(): void
    {
        $this->handle(self::request('GET', '/accounts', ['X-Api-Key' => 'secret']));

        self::assertSame([['bearer', ['accounts:read']]], $this->bearer->calls);
        self::assertSame([['apiKey', []]], $this->apiKey->calls);
        self::assertSame('secret', $this->attributes['subject:apiKey']);
        self::assertArrayNotHasKey('subject:bearer', $this->attributes);
    }

    public function testTheFirstSatisfiedAlternativeWins(): void
    {
        $this->handle(self::request('GET', '/accounts', ['Authorization' => 'Bearer reader', 'X-Api-Key' => 'secret']));

        self::assertSame([], $this->apiKey->calls);
    }

    public function testWhenEveryAlternativeFailsTheMostInformativeErrorIsReported(): void
    {
        // No credentials at all: the first failure.
        self::assertSame('Bearer error="invalid_token"', $this->failure(self::request('GET', '/accounts'))->headers['WWW-Authenticate']);

        // A valid token without the scope says more than the missing API key.
        $contract = self::contractWith(
            ['security' => [['apiKey' => []], ['bearer' => ['admin']]]],
            components: ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer'], 'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']]],
        );

        self::assertSame(403, $this->failure(self::request('GET', '/things', ['Authorization' => 'Bearer reader']), $contract)->status);
    }

    public function testEverySchemeOfAnAlternativeIsRequired(): void
    {
        $contract = self::contractWith(
            ['security' => [['bearer' => [], 'apiKey' => []]]],
            components: ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer'], 'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']]],
        );

        self::assertSame(401, $this->failure(self::request('GET', '/things', ['Authorization' => 'Bearer reader']), $contract)->status);

        $this->handle(self::request('GET', '/things', ['Authorization' => 'Bearer reader', 'X-Api-Key' => 'secret']), $contract);

        self::assertSame('Bearer reader', $this->attributes['subject:bearer']);
        self::assertSame('secret', $this->attributes['subject:apiKey']);
    }

    public function testAnEmptyAlternativeAllowsAnonymousAccess(): void
    {
        $contract = Contract::fromDocument(json_decode((string) json_encode([
            'openapi' => '3.1.0',
            'paths' => ['/things' => ['get' => ['operationId' => 'test', 'security' => [['bearer' => []], new stdClass()]]]],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
        ]), flags: JSON_THROW_ON_ERROR));

        $this->handle(self::request('GET', '/things'), $contract);
        $this->handle(self::request('GET', '/things', ['Authorization' => 'Bearer reader']), $contract);

        self::assertSame('Bearer reader', $this->attributes['subject:bearer']);
    }

    public function testASchemeWithoutAuthenticatorFailsClosed(): void
    {
        $pipeline = new Pipeline(
            new OperationDispatcher(new OperationHandlers()),
            new RoutingMiddleware(new Router(self::contract())),
            new SecurityMiddleware(self::contract(), []),
        );

        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('Operation "openAccount" requires the security scheme "bearer", which has no authenticator.');

        $pipeline->handle(self::request('POST', '/accounts', ['Authorization' => 'Bearer writer']));
    }

    public function testOtherErrorsOfAnAuthenticatorAreNotSwallowed(): void
    {
        $failing = new class implements Authenticator {
            public function authenticate(ServerRequestInterface $request, \Acta\Http\OpenApi\SecurityScheme $scheme, array $scopes): ServerRequestInterface
            {
                throw new HttpException(503, 'The identity provider cannot be reached.');
            }
        };
        $pipeline = new Pipeline(
            new OperationDispatcher(new OperationHandlers()),
            new RoutingMiddleware(new Router(self::contract())),
            new SecurityMiddleware(self::contract(), ['bearer' => $failing, 'apiKey' => $this->apiKey]),
        );

        try {
            $pipeline->handle(self::request('GET', '/accounts', ['X-Api-Key' => 'secret']));
            self::fail('The failure should not be hidden by the other alternative.');
        } catch (HttpException $exception) {
            self::assertSame(503, $exception->status);
        }
    }

    public function testAFailureOfTheHandlerIsNotAnAuthenticationFailure(): void
    {
        $pipeline = new Pipeline(
            new OperationDispatcher(new OperationHandlers()->on('listAccounts', static fn() => throw HttpException::forbidden('Not your account.'))),
            new RoutingMiddleware(new Router(self::contract())),
            new SecurityMiddleware(self::contract(), ['bearer' => $this->bearer, 'apiKey' => $this->apiKey]),
        );

        try {
            $pipeline->handle(self::request('GET', '/accounts', ['Authorization' => 'Bearer reader']));
            self::fail('The error of the handler should be rethrown.');
        } catch (HttpException $exception) {
            self::assertSame('Not your account.', $exception->getMessage());
            // The second alternative was not tried after the handler failed.
            self::assertSame([], $this->apiKey->calls);
        }
    }

    private function handle(ServerRequestInterface $request, ?Contract $contract = null): void
    {
        $contract ??= self::contract();
        $handlers = new OperationHandlers();

        foreach (array_keys($contract->operations) as $operationId) {
            $handlers->on((string) $operationId, function (ServerRequestInterface $request) {
                $this->attributes = $request->getAttributes();

                return self::responder()->empty();
            });
        }

        new Pipeline(
            new OperationDispatcher($handlers),
            new RoutingMiddleware(new Router($contract)),
            new SecurityMiddleware($contract, ['bearer' => $this->bearer, 'apiKey' => $this->apiKey]),
        )->handle($request);
    }

    private function failure(ServerRequestInterface $request, ?Contract $contract = null): HttpException
    {
        try {
            $this->handle($request, $contract);
        } catch (HttpException $exception) {
            return $exception;
        }

        throw new RuntimeException('The request should have been refused.');
    }
}
