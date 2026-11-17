<?php

declare(strict_types=1);

namespace Acta\Http\Routing;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Routes each request to an operation of the contract.
 *
 * The {@see RouteMatch} is stored as a request attribute. A path the contract does not
 * declare is a 404, a method it does not declare for that path a 405 with an `Allow` header.
 */
final readonly class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Router $router,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        return $handler->handle($request->withAttribute(RouteMatch::class, $match));
    }
}
