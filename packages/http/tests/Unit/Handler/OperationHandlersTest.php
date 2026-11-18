<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Handler;

use Acta\Http\Exception\ContractError;
use Acta\Http\Exception\HttpException;
use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Handler\OperationHandlers;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Testing\ContractAssertions;
use Acta\Http\Testing\Pipeline;
use Acta\Http\Tests\Fixture\ArrayContainer;
use Acta\Http\Tests\Fixture\CloseAccountHandler;
use Acta\Http\Tests\Fixture\ReadAccountsHandler;
use Acta\Http\Tests\HttpTestCase;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class OperationHandlersTest extends HttpTestCase
{
    public function testHandlersAreAttachedByAttribute(): void
    {
        $close = new CloseAccountHandler(self::responder());
        $read = new ReadAccountsHandler(self::responder());
        $handlers = new OperationHandlers()->register($close, $read);

        self::assertSame($close, $handlers->get('closeAccount'));
        self::assertSame($read, $handlers->get('listAccounts'));
        self::assertSame($read, $handlers->get('searchAccounts'));
        self::assertTrue($handlers->has('closeAccount'));
        self::assertFalse($handlers->has('openAccount'));
        self::assertNull($handlers->get('openAccount'));
    }

    public function testAClosureCanHandleAnOperation(): void
    {
        $handlers = new OperationHandlers()->on(
            'searchAccounts',
            static fn(ServerRequestInterface $request) => self::responder()->json([$request->getUri()->getQuery()]),
        );

        self::assertSame(['q=ada'], self::json(self::dispatch($handlers, self::request('GET', '/accounts/search?q=ada'))));
    }

    public function testLazyHandlersAreFetchedFromTheContainerOnFirstUse(): void
    {
        $container = new ArrayContainer([
            CloseAccountHandler::class => new CloseAccountHandler(self::responder()),
            ReadAccountsHandler::class => new ReadAccountsHandler(self::responder()),
        ]);
        $handlers = new OperationHandlers($container)->registerLazy(CloseAccountHandler::class, ReadAccountsHandler::class);

        self::assertSame([], $container->requested);
        self::assertTrue($handlers->has('listAccounts'));

        self::assertSame(['operation' => 'listAccounts'], self::json(self::dispatch($handlers, self::request('GET', '/accounts'))));
        self::dispatch($handlers, self::request('GET', '/accounts'));

        self::assertSame([ReadAccountsHandler::class], $container->requested);
    }

    public function testLazyHandlersNeedAContainer(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('Lazy handlers need a container');

        new OperationHandlers()->registerLazy(CloseAccountHandler::class);
    }

    public function testTheContainerMustReturnARequestHandler(): void
    {
        $handlers = new OperationHandlers(new ArrayContainer([CloseAccountHandler::class => new stdClass()]))
            ->registerLazy(CloseAccountHandler::class);

        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('did not return a request handler');

        $handlers->get('closeAccount');
    }

    public function testAHandlerWithoutAttributeCannotBeRegistered(): void
    {
        $handler = new OperationDispatcher(new OperationHandlers());

        $this->expectException(ContractError::class);
        $this->expectExceptionMessage(OperationDispatcher::class . ' has no #[Operation] attribute.');

        new OperationHandlers()->register($handler);
    }

    public function testAnOperationHasOneHandler(): void
    {
        $handlers = new OperationHandlers()->register(new CloseAccountHandler(self::responder()));

        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('Operation "closeAccount" already has a handler.');

        $handlers->on('closeAccount', static fn() => self::responder()->empty());
    }

    public function testAnOperationWithoutHandlerIsNotImplemented(): void
    {
        try {
            self::dispatch(new OperationHandlers(), self::request('GET', '/accounts'));
            self::fail('The operation should not be implemented.');
        } catch (HttpException $exception) {
            self::assertSame(501, $exception->status);
            self::assertSame('Operation "listAccounts" is not implemented yet.', $exception->getMessage());
        }
    }

    public function testHandlersAreComparedWithTheContract(): void
    {
        $handlers = new OperationHandlers()
            ->register(new CloseAccountHandler(self::responder()), new ReadAccountsHandler(self::responder()))
            ->on('renamedOperation', static fn() => self::responder()->empty());

        self::assertSame(
            ['unhandled' => ['openAccount', 'getAccount', 'depositMoney', 'getStatement'], 'unknown' => ['renamedOperation']],
            $handlers->compare(self::contract()),
        );

        try {
            ContractAssertions::assertContractIsImplemented(self::contract(), $handlers);
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('Handlers and operations of the contract differ.', $failure->getMessage());

            return;
        }

        self::fail('The assertion should fail.');
    }

    public function testACompleteSetOfHandlersImplementsTheContract(): void
    {
        $handlers = new OperationHandlers();

        foreach (array_keys(self::contract()->operations) as $operationId) {
            $handlers->on((string) $operationId, static fn() => self::responder()->empty());
        }

        ContractAssertions::assertContractIsImplemented(self::contract(), $handlers);
    }

    public function testAPipelineWithoutMiddlewareCallsItsHandler(): void
    {
        $handler = new CloseAccountHandler(self::responder());

        self::assertSame(204, new Pipeline($handler)->handle(self::request('GET', '/'))->getStatusCode());
    }

    public function testResponsesAreBuiltWithoutKnowingThePsr7Implementation(): void
    {
        $responder = self::responder();

        $json = $responder->json(['café' => 'https://example.test/a', 'ratio' => 1.0], 200, ['X-One' => '1']);

        self::assertSame('{"café":"https://example.test/a","ratio":1.0}', (string) $json->getBody());
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));
        self::assertSame('1', $json->getHeaderLine('X-One'));

        $created = $responder->created('/accounts/1', ['id' => 1], ['ETag' => '"1"']);

        self::assertSame([201, '/accounts/1', '"1"', '{"id":1}'], [$created->getStatusCode(), $created->getHeaderLine('Location'), $created->getHeaderLine('ETag'), (string) $created->getBody()]);
        self::assertSame('', (string) $responder->created('/accounts/1')->getBody());
        self::assertSame(202, $responder->accepted()->getStatusCode());
        self::assertSame('{"version":3}', (string) $responder->accepted(['version' => 3])->getBody());
        self::assertSame(204, $responder->empty()->getStatusCode());
        self::assertFalse($responder->empty()->hasHeader('Content-Type'));
    }

    private static function dispatch(OperationHandlers $handlers, ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new Pipeline(new OperationDispatcher($handlers), new RoutingMiddleware(new Router(self::contract())))->handle($request);
    }
}
