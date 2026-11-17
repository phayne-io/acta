<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use Acta\Http\Routing\RouteMatch;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects requests that do not match the contract and hands the others to the handler
 * with their {@see Input}.
 *
 * Place it after {@see \Acta\Http\Routing\RoutingMiddleware}. Handlers then never
 * validate anything themselves: what reaches them already has the declared shape.
 */
final readonly class RequestValidationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestValidator $validator,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $input = $this->validator->validate($request, RouteMatch::from($request));

        if (is_array($input->body)) {
            $request = $request->withParsedBody($input->body);
        }

        return $handler->handle($request->withAttribute(Input::class, $input));
    }
}
