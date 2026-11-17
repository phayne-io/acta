<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by an event bus configured to collect listener errors.
 *
 * Every listener of the event has been invoked; the exceptions raised along the way are
 * available through {@see $failures}.
 */
final class ListenerFailures extends RuntimeException implements ActaException
{
    /**
     * @param non-empty-list<Throwable> $failures
     */
    public function __construct(
        string $messageName,
        public readonly array $failures,
    ) {
        parent::__construct(
            sprintf(
                '%d listener(s) failed while handling "%s": %s',
                count($failures),
                $messageName,
                implode('; ', array_map(static fn(Throwable $failure): string => $failure->getMessage(), $failures)),
            ),
            previous: $failures[0],
        );
    }
}
