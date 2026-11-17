<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus\Middleware;

use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\TransactionManager;
use Closure;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles a command again when it fails on an optimistic concurrency conflict.
 *
 * The handler reloads the aggregate on each attempt, so the retry runs against the
 * state written by the competing transaction. Place this middleware outside the
 * {@see TransactionMiddleware}: a conflict rolls the transaction back, and the retry
 * needs a new one.
 */
final readonly class RetryOnConflictMiddleware implements Middleware
{
    /**
     * @param int                     $maxAttempts  total number of attempts, the first one included
     * @param TransactionManager|null $transactions when given, a command dispatched while a transaction
     *                                              is already open is not retried, since only the
     *                                              outermost unit of work can be replayed safely
     * @param LoggerInterface         $logger       PSR-3 logger receiving a warning for each retry
     */
    public function __construct(
        private int $maxAttempts = 3,
        private ?TransactionManager $transactions = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgument('The number of attempts must be at least 1.');
        }
    }

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        if ($this->transactions?->inTransaction() === true) {
            return $next($envelope);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $next($envelope);
            } catch (ConcurrencyConflict $conflict) {
                if ($attempt >= $this->maxAttempts) {
                    throw $conflict;
                }

                $this->logger->warning('Retrying {message} after a concurrency conflict (attempt {attempt}): {error}', [
                    'message' => $envelope->name,
                    'message_id' => $envelope->id->value,
                    'attempt' => $attempt + 1,
                    'error' => $conflict->getMessage(),
                    'exception' => $conflict,
                ]);
            }
        }
    }
}
