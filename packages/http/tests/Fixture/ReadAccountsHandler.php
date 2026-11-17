<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture;

use Acta\Http\Handler\Operation;
use Acta\Http\Response\Responder;
use Acta\Http\Routing\RouteMatch;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handler serving several operations.
 */
#[Operation('listAccounts')]
#[Operation('searchAccounts')]
final readonly class ReadAccountsHandler implements RequestHandlerInterface
{
    public function __construct(
        private Responder $responder,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['operation' => RouteMatch::from($request)->operation->id]);
    }
}
