<?php

declare(strict_types=1);

namespace Acta\Http\Trace;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request a {@see TraceContext}, continuing the trace of the caller when
 * it sent a valid `traceparent` header.
 *
 * The context is stored as a request attribute and echoed in a response header, so
 * that a client can quote it when reporting a problem. Place this middleware first:
 * error responses then carry the trace as well.
 */
final readonly class CorrelationMiddleware implements MiddlewareInterface
{
    /**
     * @param string $responseHeader header carrying the trace in responses; empty to send none
     */
    public function __construct(
        private string $responseHeader = 'traceparent',
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = TraceContext::continue($request->getHeaderLine('traceparent')) ?? TraceContext::start();
        $response = $handler->handle($request->withAttribute(TraceContext::class, $context));

        return $this->responseHeader === ''
            ? $response
            : $response->withHeader($this->responseHeader, $context->toHeader());
    }
}
