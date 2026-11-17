<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\StreamName;
use RuntimeException;
use Throwable;

/**
 * Thrown when events are appended to a stream that is not at the expected version.
 *
 * It signals an optimistic concurrency failure: another writer changed the stream since
 * the aggregate was loaded. The command can usually be retried from a fresh state, which
 * is what {@see \Acta\EventSourcing\Bus\Middleware\RetryOnConflictMiddleware} does.
 */
final class ConcurrencyConflict extends RuntimeException implements ActaException
{
    public static function versionMismatch(
        StreamName $stream,
        ExpectedVersion $expected,
        int $actual,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Stream "%s" is at version %d, expected %s.',
                $stream->value,
                $actual,
                $expected->describe(),
            ),
            previous: $previous,
        );
    }
}
