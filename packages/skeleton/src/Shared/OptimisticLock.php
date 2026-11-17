<?php

declare(strict_types=1);

namespace App\Shared;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use ReflectionClass;

/**
 * Lets a command state which version of an aggregate its author has seen.
 *
 * A client that reads a resource, shows it to a person and sends a change some time
 * later acts on the version it read. When the aggregate moved in the meantime, the
 * command is refused rather than applied to a state nobody looked at. Over HTTP the
 * version travels in the `ETag` and `If-Match` headers.
 *
 * The repository still guards the write itself: this check only widens the window from
 * "since the handler loaded the aggregate" to "since the client read it".
 */
final class OptimisticLock
{
    /**
     * @param ExpectedVersion|null $expected `null` when the author of the command stated nothing
     *
     * @throws ConcurrencyConflict when the aggregate is not at the expected version
     */
    public static function assert(AggregateRoot $aggregate, ?ExpectedVersion $expected): void
    {
        if ($expected !== null && !$expected->isSatisfiedBy($aggregate->version)) {
            throw new ConcurrencyConflict(sprintf(
                '%s "%s" is at version %d, expected %s.',
                new ReflectionClass($aggregate)->getShortName(),
                $aggregate->aggregateId(),
                $aggregate->version,
                $expected->describe(),
            ));
        }
    }
}
