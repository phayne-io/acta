<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture;

use Acta\Http\Handler\Operation;
use Acta\Http\Response\Responder;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handler attached to its operation by attribute.
 */
#[Operation('closeAccount')]
final readonly class CloseAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private Responder $responder,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->empty();
    }
}
