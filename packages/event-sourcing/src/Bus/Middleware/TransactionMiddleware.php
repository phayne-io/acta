<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus\Middleware;

use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\TransactionManager;
use Closure;
use Override;

/**
 * Handles each message inside a transaction.
 *
 * It is the equivalent of the `TransactionManager` plugin of Prooph's
 * event-store-bus-bridge: the events appended and the read models updated while a
 * command is handled commit together, or not at all.
 */
final readonly class TransactionMiddleware implements Middleware
{
    public function __construct(
        private TransactionManager $transactions,
    ) {}

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        return $this->transactions->transactional(static fn(): mixed => $next($envelope));
    }
}
