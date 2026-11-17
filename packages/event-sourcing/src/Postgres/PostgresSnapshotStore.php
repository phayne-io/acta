<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Postgres;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Snapshot\Snapshot;
use Acta\EventSourcing\Snapshot\SnapshotStore;
use Closure;
use DateTimeImmutable;
use Override;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Snapshot store on PostgreSQL. See {@see PostgresSchema} for the table.
 *
 * Aggregates are stored with PHP's native serialization by default, as Prooph's
 * `CallbackSerializer` did. Pass your own pair of callbacks to use another format, for
 * instance to compress snapshots. A snapshot that cannot be read back is treated as
 * missing, so the aggregate is simply rebuilt from its events; a warning is logged.
 */
final readonly class PostgresSnapshotStore implements SnapshotStore
{
    private string $table;

    /** @var Closure(AggregateRoot): string */
    private Closure $serialize;

    /** @var Closure(string): mixed */
    private Closure $unserialize;

    /**
     * @param string                                $table       name of the snapshots table, as given to {@see PostgresSchema}
     * @param (Closure(AggregateRoot): string)|null $serialize   turns an aggregate into a binary string
     * @param (Closure(string): mixed)|null         $unserialize does the reverse; anything that is not an
     *                                                           aggregate is treated as a missing snapshot
     * @param LoggerInterface                       $logger      PSR-3 logger warned when a stored snapshot cannot be read back
     */
    public function __construct(
        private PDO $pdo,
        string $table = 'snapshots',
        ?Closure $serialize = null,
        ?Closure $unserialize = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->table = PostgresSchema::identifier($table);
        $this->serialize = $serialize ?? serialize(...);
        // A snapshot that cannot be read is a cache miss, not an error: the events are still there.
        $this->unserialize = $unserialize ?? static fn(string $binary): mixed => @unserialize($binary);
    }

    #[Override]
    public function get(string $aggregateType, string $aggregateId): ?Snapshot
    {
        $statement = $this->pdo->prepare(
            "SELECT version, created_at, aggregate FROM {$this->table} WHERE aggregate_type = ? AND aggregate_id = ?",
        );
        $statement->execute([$aggregateType, $aggregateId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        // PDO returns BYTEA columns as streams.
        $binary = is_resource($row['aggregate']) ? stream_get_contents($row['aggregate']) : $row['aggregate'];
        $aggregate = is_string($binary) ? ($this->unserialize)($binary) : null;

        if (!$aggregate instanceof AggregateRoot) {
            $this->logger->warning(
                'The snapshot of {aggregate_type} {aggregate_id} cannot be read and is ignored.',
                ['aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId],
            );

            return null;
        }

        return new Snapshot(
            $aggregateType,
            $aggregateId,
            $aggregate,
            Row::int($row, 'version'),
            new DateTimeImmutable(Row::string($row, 'created_at')),
        );
    }

    #[Override]
    public function save(Snapshot ...$snapshots): void
    {
        $statement = $this->pdo->prepare(<<<SQL
            INSERT INTO {$this->table} (aggregate_type, aggregate_id, version, created_at, aggregate)
            VALUES (:type, :id, :version, :created_at, :aggregate)
            ON CONFLICT (aggregate_type, aggregate_id) DO UPDATE SET
                version = EXCLUDED.version,
                created_at = EXCLUDED.created_at,
                aggregate = EXCLUDED.aggregate
            SQL);

        foreach ($snapshots as $snapshot) {
            $statement->bindValue(':type', $snapshot->aggregateType);
            $statement->bindValue(':id', $snapshot->aggregateId);
            $statement->bindValue(':version', $snapshot->version, PDO::PARAM_INT);
            $statement->bindValue(':created_at', $snapshot->createdAt->format('Y-m-d\TH:i:s.uP'));
            $statement->bindValue(':aggregate', ($this->serialize)($snapshot->aggregate), PDO::PARAM_LOB);
            $statement->execute();
        }
    }

    #[Override]
    public function removeAll(string $aggregateType): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE aggregate_type = ?")->execute([$aggregateType]);
    }
}
