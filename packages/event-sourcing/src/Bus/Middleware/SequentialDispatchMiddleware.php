<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus\Middleware;

use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Message\Envelope;
use Closure;
use Override;

/**
 * Queues the commands dispatched while another command is being handled.
 *
 * Prooph's command bus did this natively: a command dispatched from a handler or a
 * listener waits for the current one to finish instead of running nested inside it.
 * Each command then sees a consistent state and gets its own transaction when this
 * middleware sits outside the {@see TransactionMiddleware}.
 *
 * Queued commands are dropped if the current command fails. Use it on a command bus
 * only: a queued message cannot return a result.
 */
final class SequentialDispatchMiddleware implements Middleware
{
    private bool $busy = false;

    /** @var list<array{Envelope, Closure(Envelope): mixed}> */
    private array $queue = [];

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        if ($this->busy) {
            $this->queue[] = [$envelope, $next];

            return null;
        }

        $this->busy = true;

        try {
            $result = $next($envelope);

            while ($this->queue !== []) {
                [$queued, $continue] = array_shift($this->queue);
                $continue($queued);
            }

            return $result;
        } finally {
            $this->busy = false;
            $this->queue = [];
        }
    }
}
