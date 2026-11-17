<?php

declare(strict_types=1);

namespace Acta\Http\Routing;

use Acta\Http\OpenApi\Operation;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The operation of the contract a request was routed to.
 *
 * {@see RoutingMiddleware} stores it as a request attribute for the middlewares that follow.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, string> $pathParameters values of the path template variables, percent-decoded
     */
    public function __construct(
        public Operation $operation,
        public array $pathParameters = [],
    ) {}

    /**
     * Returns the match of a routed request.
     *
     * @throws LogicException when the request did not go through {@see RoutingMiddleware}
     */
    public static function from(ServerRequestInterface $request): self
    {
        return self::tryFrom($request)
            ?? throw new LogicException('This request was not routed: add RoutingMiddleware to the pipeline.');
    }

    public static function tryFrom(ServerRequestInterface $request): ?self
    {
        $match = $request->getAttribute(self::class);

        return $match instanceof self ? $match : null;
    }
}
