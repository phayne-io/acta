<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Routing;

use Acta\Http\Exception\HttpException;
use Acta\Http\OpenApi\Contract;
use Acta\Http\Pipeline;
use Acta\Http\Routing\RouteMatch;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Tests\HttpTestCase;
use LogicException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouterTest extends HttpTestCase
{
    public function testItFindsTheOperationOfAMethodAndAPath(): void
    {
        $router = new Router(self::contract());

        self::assertSame('openAccount', $router->match('POST', '/accounts')->operation->id);
        self::assertSame('listAccounts', $router->match('get', '/accounts')->operation->id);
        self::assertSame([], $router->match('GET', '/accounts')->pathParameters);

        $match = $router->match('POST', '/accounts/' . self::ACCOUNT_ID . '/deposits');

        self::assertSame('depositMoney', $match->operation->id);
        self::assertSame(['accountId' => self::ACCOUNT_ID], $match->pathParameters);
    }

    public function testAConcretePathWinsOverATemplate(): void
    {
        $router = new Router(self::contract());

        self::assertSame('searchAccounts', $router->match('GET', '/accounts/search')->operation->id);
        self::assertSame('getAccount', $router->match('GET', '/accounts/searching')->operation->id);
    }

    public function testTheMostSpecificTemplateWins(): void
    {
        $operation = static fn(string $id, string ...$variables): array => ['get' => [
            'operationId' => $id,
            'parameters' => array_map(static fn(string $name): array => ['name' => $name, 'in' => 'path', 'required' => true], $variables),
        ]];
        $router = new Router(Contract::fromDocument(['openapi' => '3.1.0', 'paths' => [
            '/{a}/{b}' => $operation('two', 'a', 'b'),
            '/files/{name}.{extension}' => $operation('typed', 'name', 'extension'),
            '/{a}/x' => $operation('one', 'a'),
        ]]));

        // Fewer variables first, then the longest template.
        self::assertSame('one', $router->match('GET', '/docs/x')->operation->id);
        self::assertSame('two', $router->match('GET', '/docs/y')->operation->id);
        self::assertSame('two', $router->match('GET', '/files/readme')->operation->id);
        self::assertSame('typed', $router->match('GET', '/files/archive.tar.gz')->operation->id);
        self::assertSame(['name' => 'archive.tar', 'extension' => 'gz'], $router->match('GET', '/files/archive.tar.gz')->pathParameters);
    }

    public function testPathParametersArePercentDecoded(): void
    {
        $contract = self::contractWith(
            ['parameters' => [['name' => 'file-name', 'in' => 'path', 'required' => true]]],
            '/files/{file-name}',
        );

        self::assertSame(
            ['file-name' => 'a b/c'],
            new Router($contract)->match('GET', '/files/a%20b%2Fc')->pathParameters,
        );
    }

    public function testAnUnknownPathIsNotFound(): void
    {
        $router = new Router(self::contract());

        foreach (['/nope', '/accounts/', '/accounts/a/b/c', '/Accounts'] as $path) {
            try {
                $router->match('GET', $path);
                self::fail($path . ' should not be found.');
            } catch (HttpException $exception) {
                self::assertSame(404, $exception->status);
            }
        }
    }

    public function testAnUndeclaredMethodIsNotAllowed(): void
    {
        $router = new Router(self::contract());

        try {
            $router->match('PUT', '/accounts');
            self::fail('PUT should not be allowed.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status);
            self::assertSame(['Allow' => 'GET, POST'], $exception->headers);
        }

        try {
            $router->match('PATCH', '/accounts/' . self::ACCOUNT_ID);
            self::fail('PATCH should not be allowed.');
        } catch (HttpException $exception) {
            self::assertSame(['Allow' => 'GET, DELETE'], $exception->headers);
        }

        // A concrete path without the method does not hide the template that has it.
        self::assertSame('closeAccount', $router->match('DELETE', '/accounts/search')->operation->id);
    }

    public function testTheBasePathIsRemovedBeforeMatching(): void
    {
        $router = new Router(self::contract(), '/api/v1');

        self::assertSame('listAccounts', $router->match('GET', '/api/v1/accounts')->operation->id);

        $this->expectException(HttpException::class);

        $router->match('GET', '/accounts');
    }

    public function testTheMiddlewareStoresTheMatchInTheRequest(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public ?RouteMatch $seen = null;

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = RouteMatch::from($request);

                return HttpTestCase::responder()->empty();
            }
        };

        new Pipeline($handler, new RoutingMiddleware(new Router(self::contract())))
            ->handle(self::request('DELETE', '/accounts/' . self::ACCOUNT_ID));

        self::assertSame('closeAccount', $handler->seen?->operation->id);
        self::assertSame(['accountId' => self::ACCOUNT_ID], $handler->seen->pathParameters);
    }

    public function testARequestThatWasNotRoutedHasNoMatch(): void
    {
        self::assertNull(RouteMatch::tryFrom(self::request('GET', '/accounts')));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('add RoutingMiddleware');

        RouteMatch::from(self::request('GET', '/accounts'));
    }
}
