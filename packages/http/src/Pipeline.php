<?php

declare(strict_types=1);

namespace Acta\Http;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs a request through middlewares, outermost first, then through a final handler.
 *
 * It is all an application needs to serve the contract without a framework, and what
 * the tests use. With Mezzio or any other PSR-15 stack, pipe the same middlewares
 * there instead.
 */
final readonly class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $middlewares;

    public function __construct(
        private RequestHandlerInterface $handler,
        MiddlewareInterface ...$middlewares,
    ) {
        $this->middlewares = array_values($middlewares);
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->next(0)->handle($request);
    }

    private function next(int $index): RequestHandlerInterface
    {
        $middleware = $this->middlewares[$index] ?? null;

        if ($middleware === null) {
            return $this->handler;
        }

        return new readonly class ($middleware, $this->next($index + 1)) implements RequestHandlerInterface {
            public function __construct(
                private MiddlewareInterface $middleware,
                private RequestHandlerInterface $next,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
