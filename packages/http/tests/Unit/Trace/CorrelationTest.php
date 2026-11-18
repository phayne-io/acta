<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Trace;

use Acta\Http\Testing\Pipeline;
use Acta\Http\Tests\HttpTestCase;
use Acta\Http\Trace\CorrelationMiddleware;
use Acta\Http\Trace\TraceContext;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorrelationTest extends HttpTestCase
{
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    public function testANewTraceIsStartedWhenTheRequestHasNone(): void
    {
        $context = TraceContext::start();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context->traceId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context->spanId);
        self::assertNull($context->parentId);
        self::assertSame(sprintf('00-%s-%s-00', $context->traceId, $context->spanId), $context->toHeader());
        self::assertNotSame($context->traceId, TraceContext::start()->traceId);
    }

    public function testTheTraceOfTheCallerIsContinuedWithANewSpan(): void
    {
        $context = TraceContext::continue(' ' . self::TRACEPARENT . ' ');

        self::assertNotNull($context);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceId);
        self::assertSame('b7ad6b7169203331', $context->parentId);
        self::assertSame('01', $context->flags);
        self::assertNotSame('b7ad6b7169203331', $context->spanId);
        self::assertSame("00-0af7651916cd43dd8448eb211c80319c-{$context->spanId}-01", $context->toHeader());
    }

    #[DataProvider('invalidHeaders')]
    public function testAnInvalidHeaderIsIgnored(string $header): void
    {
        self::assertNull(TraceContext::continue($header));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['trace-me'];
        yield 'unknown version' => ['01-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'];
        yield 'upper case' => ['00-0AF7651916CD43DD8448EB211C80319C-b7ad6b7169203331-01'];
        yield 'short trace' => ['00-0af7651916cd43dd-b7ad6b7169203331-01'];
        yield 'null trace' => ['00-00000000000000000000000000000000-b7ad6b7169203331-01'];
        yield 'null span' => ['00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'];
    }

    public function testTheMiddlewareSharesTheContextWithHandlersAndClients(): void
    {
        $handler = self::capturing();
        $response = new Pipeline($handler, new CorrelationMiddleware())
            ->handle(self::request('GET', '/', ['traceparent' => self::TRACEPARENT]));

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $handler->context?->traceId);
        self::assertSame($handler->context->toHeader(), $response->getHeaderLine('traceparent'));
    }

    public function testTheMiddlewareStartsATraceForRequestsWithoutOne(): void
    {
        $handler = self::capturing();
        $response = new Pipeline($handler, new CorrelationMiddleware('X-Trace'))
            ->handle(self::request('GET', '/', ['traceparent' => 'nope']));

        self::assertNotNull($handler->context);
        self::assertNull($handler->context->parentId);
        self::assertSame($handler->context->toHeader(), $response->getHeaderLine('X-Trace'));
        self::assertFalse($response->hasHeader('traceparent'));
    }

    public function testTheResponseHeaderCanBeTurnedOff(): void
    {
        $response = new Pipeline(self::capturing(), new CorrelationMiddleware(''))->handle(self::request('GET', '/'));

        self::assertSame(['Content-Type'], array_keys($response->getHeaders()));
    }

    public function testARequestWithoutTheMiddlewareHasNoContext(): void
    {
        self::assertNull(TraceContext::tryFrom(self::request('GET', '/')));
    }

    /**
     * @return RequestHandlerInterface&object{context: ?TraceContext}
     */
    private static function capturing(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?TraceContext $context = null;

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->context = TraceContext::tryFrom($request);

                return HttpTestCase::responder()->json(['ok' => true]);
            }
        };
    }
}
