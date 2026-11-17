<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Postgres;

use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\Projection\Checkpoint;
use Acta\EventSourcing\Projection\ProjectionStatus;
use Acta\EventSourcing\Projection\ProjectionStore;
use Override;
use PDO;

/**
 * Projection checkpoints on PostgreSQL. See {@see PostgresSchema} for the table.
 *
 * Exclusive execution relies on session-level advisory locks: the lock belongs to the
 * database connection and PostgreSQL releases it by itself if the process dies, so a
 * crashed projection never stays locked, unlike the timestamp-based locks of Prooph.
 */
final readonly class PostgresProjectionStore implements ProjectionStore
{
    /** First key of the advisory locks taken by this class: "ACTA" in ASCII. */
    private const int LOCK_NAMESPACE = 0x41435441;

    private string $table;

    /**
     * @param string $table name of the checkpoints table, as given to {@see PostgresSchema}
     */
    public function __construct(
        private PDO $pdo,
        string $table = 'projections',
    ) {
        $this->table = PostgresSchema::identifier($table);
    }

    #[Override]
    public function find(string $name): ?Checkpoint
    {
        $rows = Row::all(
            $this->pdo,
            "SELECT transaction_id::text AS transaction_id, position, state, status FROM {$this->table} WHERE name = ?",
            [$name],
        );

        if ($rows === []) {
            return null;
        }

        return new Checkpoint(
            new Position(Row::int($rows[0], 'transaction_id'), Row::int($rows[0], 'position')),
            Row::json($rows[0], 'state'),
            ProjectionStatus::from(Row::string($rows[0], 'status')),
        );
    }

    #[Override]
    public function save(string $name, Position $position, array $state): void
    {
        $this->pdo->prepare(<<<SQL
            INSERT INTO {$this->table} (name, transaction_id, position, state)
            VALUES (?, ?::xid8, ?, ?::jsonb)
            ON CONFLICT (name) DO UPDATE SET
                transaction_id = EXCLUDED.transaction_id,
                position = EXCLUDED.position,
                state = EXCLUDED.state,
                updated_at = now()
            SQL)->execute([
            $name,
            $position->transaction,
            $position->sequence,
            Row::encode($state),
        ]);
    }

    #[Override]
    public function setStatus(string $name, ProjectionStatus $status): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET status = ?, updated_at = now() WHERE name = ?")
            ->execute([$status->value, $name]);
    }

    #[Override]
    public function delete(string $name): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE name = ?")->execute([$name]);
    }

    #[Override]
    public function names(): array
    {
        return array_map(
            static fn(array $row): string => Row::string($row, 'name'),
            Row::all($this->pdo, "SELECT name FROM {$this->table} ORDER BY name COLLATE \"C\""),
        );
    }

    #[Override]
    public function acquireLock(string $name): bool
    {
        $rows = Row::all(
            $this->pdo,
            'SELECT pg_try_advisory_lock(?::int, hashtext(?::text)) AS acquired',
            [self::LOCK_NAMESPACE, $name],
        );

        return ($rows[0]['acquired'] ?? false) === true;
    }

    #[Override]
    public function releaseLock(string $name): void
    {
        $this->pdo
            ->prepare('SELECT pg_advisory_unlock(?::int, hashtext(?::text))')
            ->execute([self::LOCK_NAMESPACE, $name]);
    }
}
