<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Acta\Http\OpenApi\Contract;
use Acta\Http\Response\Responder;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the OpenAPI contract the API is validated against, so that what clients read
 * is what the server enforces.
 */
final readonly class ContractAction implements RequestHandlerInterface
{
    public function __construct(
        private Contract $contract,
        private Responder $responder,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->contract->document, headers: ['Cache-Control' => 'public, max-age=300']);
    }
}
