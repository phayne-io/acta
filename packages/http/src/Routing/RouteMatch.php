<?php

declare(strict_types=1);

namespace Acta\Http\Routing;

use Acta\Http\OpenApi\Operation;
use LogicException;
use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The operation of the contract a request was routed to.
 *
 * It is all the middlewares that follow know about routing: they read it from the
 * request attribute named after this class, and never ask who put it there.
 * {@see RoutingMiddleware} does, with the {@see Router} of the contract. An application
 * that routes with something else stores it itself, with {@see RouteMatch::attach()}:
 * the operation its own route stands for, and the values of the path variables.
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
     * @throws LogicException when nothing routed the request
     */
    public static function from(ServerRequestInterface $request): self
    {
        return self::tryFrom($request)
            ?? throw new LogicException('This request was not routed: add RoutingMiddleware to the pipeline, or attach a RouteMatch to the request.');
    }

    /**
     * Returns the request, routed to this operation.
     *
     * What {@see RoutingMiddleware} does, for a router of your own.
     */
    #[NoDiscard]
    public function attach(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute(self::class, $this);
    }

    public static function tryFrom(ServerRequestInterface $request): ?self
    {
        $match = $request->getAttribute(self::class);

        return $match instanceof self ? $match : null;
    }
}
