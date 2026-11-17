<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Postgres;

use Acta\EventSourcing\Exception\InvalidArgument;
use PDO;

/**
 * DDL of the tables used by the PostgreSQL adapters. Requires PostgreSQL 14 or later.
 *
 * Where Prooph created one table per stream and offered several persistence strategies,
 * there is a single append-only table for all events:
 *
 * - `(stream_name, stream_version)` is unique, which is the optimistic concurrency check;
 * - `(transaction_id, position)` is the global order read by projections, see
 *   {@see \Acta\EventSourcing\EventStore\Position}.
 *
 * Run {@see install()} once, or copy {@see statements()} into your migration tool.
 */
final readonly class PostgresSchema
{
    public string $eventTable;

    public string $projectionTable;

    public string $snapshotTable;

    /**
     * @param string $eventTable      name of the events table, optionally schema-qualified
     * @param string $projectionTable name of the projection checkpoints table
     * @param string $snapshotTable   name of the snapshots table
     *
     * @throws InvalidArgument when a name is not a plain SQL identifier
     */
    public function __construct(
        string $eventTable = 'event_store',
        string $projectionTable = 'projections',
        string $snapshotTable = 'snapshots',
    ) {
        $this->eventTable = self::identifier($eventTable);
        $this->projectionTable = self::identifier($projectionTable);
        $this->snapshotTable = self::identifier($snapshotTable);
    }

    /**
     * Validates a table name, since identifiers cannot be bound as query parameters.
     *
     * @throws InvalidArgument
     */
    public static function identifier(string $name): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $name) !== 1) {
            throw new InvalidArgument(sprintf('"%s" is not a valid table name.', $name));
        }

        return $name;
    }

    /**
     * Returns the idempotent statements creating the tables and their indexes.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $events = $this->eventTable;
        $prefix = self::unqualified($events);

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$events} (
                    position        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                    transaction_id  XID8        NOT NULL DEFAULT pg_current_xact_id(),
                    event_id        UUID        NOT NULL,
                    stream_name     TEXT        NOT NULL,
                    stream_category TEXT        NOT NULL,
                    stream_version  INTEGER     NOT NULL CHECK (stream_version > 0),
                    event_name      TEXT        NOT NULL,
                    schema_version  SMALLINT    NOT NULL DEFAULT 1,
                    payload         JSONB       NOT NULL,
                    metadata        JSONB       NOT NULL DEFAULT '{}',
                    created_at      TIMESTAMPTZ NOT NULL,
                    CONSTRAINT {$prefix}_event_id_key UNIQUE (event_id),
                    CONSTRAINT {$prefix}_stream_version_key UNIQUE (stream_name, stream_version)
                )
                SQL,
            "CREATE INDEX IF NOT EXISTS {$prefix}_feed_idx ON {$events} (transaction_id, position)",
            "CREATE INDEX IF NOT EXISTS {$prefix}_category_idx ON {$events} (stream_category, transaction_id, position)",
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$this->projectionTable} (
                    name           TEXT        PRIMARY KEY,
                    transaction_id XID8        NOT NULL DEFAULT '0',
                    position       BIGINT      NOT NULL DEFAULT 0,
                    state          JSONB       NOT NULL DEFAULT '{}',
                    status         TEXT        NOT NULL DEFAULT 'idle',
                    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
                )
                SQL,
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$this->snapshotTable} (
                    aggregate_type TEXT        NOT NULL,
                    aggregate_id   TEXT        NOT NULL,
                    version        INTEGER     NOT NULL,
                    created_at     TIMESTAMPTZ NOT NULL,
                    aggregate      BYTEA       NOT NULL,
                    PRIMARY KEY (aggregate_type, aggregate_id)
                )
                SQL,
        ];
    }

    /**
     * Creates the tables and indexes that do not exist yet.
     */
    public function install(PDO $pdo): void
    {
        foreach ($this->statements() as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Drops the tables. Every event, checkpoint and snapshot is lost.
     */
    public function drop(PDO $pdo): void
    {
        foreach ([$this->eventTable, $this->projectionTable, $this->snapshotTable] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Returns a table name without its schema, to build constraint and index names.
     */
    public static function unqualified(string $table): string
    {
        return array_last(explode('.', $table));
    }
}
