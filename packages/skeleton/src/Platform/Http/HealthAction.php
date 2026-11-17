<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Acta\Http\Response\Responder;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Liveness probe: tells an orchestrator that the process answers.
 *
 * It deliberately checks nothing else. A probe that fails when the database is slow
 * gets every instance restarted at the worst possible moment.
 */
final readonly class HealthAction implements RequestHandlerInterface
{
    public function __construct(
        private Responder $responder,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['status' => 'ok'], headers: ['Cache-Control' => 'no-store']);
    }
}
