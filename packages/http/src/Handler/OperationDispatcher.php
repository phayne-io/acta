<?php

declare(strict_types=1);

namespace Acta\Http\Handler;

use Acta\Http\Exception\HttpException;
use Acta\Http\Routing\RouteMatch;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Last element of the pipeline: calls the handler of the routed operation.
 *
 * An operation the contract declares but nobody implements answers 501.
 */
final readonly class OperationDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private OperationHandlers $handlers,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $operation = RouteMatch::from($request)->operation;
        $handler = $this->handlers->get($operation->id)
            ?? throw HttpException::notImplemented(sprintf('Operation "%s" is not implemented yet.', $operation->id));

        return $handler->handle($request);
    }
}
