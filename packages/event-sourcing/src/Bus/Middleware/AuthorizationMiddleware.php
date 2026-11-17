<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus\Middleware;

use Acta\EventSourcing\Bus\Authorizer;
use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Exception\AccessDenied;
use Acta\EventSourcing\Message\Envelope;
use Closure;
use Override;

/**
 * Refuses messages the {@see Authorizer} does not grant, before any handler runs.
 */
final readonly class AuthorizationMiddleware implements Middleware
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        if (!$this->authorizer->isGranted($envelope)) {
            throw new AccessDenied(sprintf('Handling "%s" is not allowed.', $envelope->name));
        }

        return $next($envelope);
    }
}
