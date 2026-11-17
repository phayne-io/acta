<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus\Middleware;

use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;
use Closure;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Reports the handling of each message to a PSR-3 logger.
 *
 * A message that is handled is logged with its duration, at a level of your choice. A
 * message whose handling fails is logged at the error level with the exception, which is
 * then rethrown untouched. Each record carries the name, the identifier and the kind of
 * the message, plus its correlation and causation identifiers when it has them.
 *
 * Place it after the {@see CausationMiddleware} so that the correlation identifier is
 * already set, and before the retry and transaction middlewares to log one record per
 * message rather than one per attempt:
 *
 * ```php
 * new DefaultCommandBus(
 *     $handlers,
 *     $envelopes,
 *     $causation,
 *     new LoggingMiddleware($logger),
 *     new RetryOnConflictMiddleware(3, $transactions, $logger),
 *     new TransactionMiddleware($transactions),
 * );
 * ```
 *
 * The payload of a message is never logged, since it may hold personal data.
 */
final readonly class LoggingMiddleware implements Middleware
{
    /**
     * @param string $level PSR-3 level of the record written when a message is handled, see {@see LogLevel}
     */
    public function __construct(
        private LoggerInterface $logger,
        private string $level = LogLevel::INFO,
    ) {}

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        $startedAt = hrtime(true);

        try {
            $result = $next($envelope);
        } catch (Throwable $failure) {
            $this->logger->error('Handling {kind} {message} failed after {duration_ms} ms: {error}', [
                ...self::context($envelope, $startedAt),
                'error' => $failure->getMessage(),
                'exception' => $failure,
            ]);

            throw $failure;
        }

        $this->logger->log($this->level, 'Handled {kind} {message} in {duration_ms} ms.', self::context($envelope, $startedAt));

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(Envelope $envelope, int|float $startedAt): array
    {
        return array_filter(
            [
                'message' => $envelope->name,
                'message_id' => $envelope->id->value,
                'kind' => $envelope->kind()->value,
                'correlation_id' => $envelope->metadata->get(Metadata::CORRELATION_ID),
                'causation_id' => $envelope->metadata->get(Metadata::CAUSATION_ID),
                'duration_ms' => round((hrtime(true) - $startedAt) / 1e6, 3),
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
