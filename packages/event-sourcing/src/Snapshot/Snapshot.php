<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Snapshot;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use DateTimeImmutable;

/**
 * State of an aggregate at a given version, saved to avoid replaying its whole stream.
 */
final readonly class Snapshot
{
    /**
     * @param string            $aggregateType stream category of the aggregate, e.g. `account`
     * @param string            $aggregateId   identifier of the aggregate
     * @param AggregateRoot     $aggregate     the aggregate as it was at `$version`
     * @param int               $version       version of the last event applied to the aggregate
     * @param DateTimeImmutable $createdAt     instant the snapshot was taken
     */
    public function __construct(
        public string $aggregateType,
        public string $aggregateId,
        public AggregateRoot $aggregate,
        public int $version,
        public DateTimeImmutable $createdAt,
    ) {}
}
