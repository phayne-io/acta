<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use Acta\EventSourcing\Postgres\PostgresSchema;
use DateTimeImmutable;
use DateTimeInterface;
use Override;
use PDO;

/**
 * Delivery queue in a PostgreSQL table.
 *
 * Deliveries are reserved with `FOR UPDATE SKIP LOCKED`, so that any number of workers
 * can run `webhook:deliver` side by side without sending a webhook twice.
 *
 * Given the connection the projections write through, the deliveries of an event are
 * enqueued in the transaction of the projection checkpoint: none is lost, none is
 * enqueued twice.
 */
final readonly class PostgresDeliveryQueue implements DeliveryQueue
{
    private const string COLUMNS = 'id, subscription_id, event_type, body, status, attempts, next_attempt_at, last_status, last_error, created_at';

    private string $table;

    /**
     * @param PDO    $pdo   a connection to PostgreSQL, with exceptions as error mode
     * @param string $table name of the table, a plain SQL identifier, optionally schema-qualified
     */
    public function __construct(
        private PDO $pdo,
        string $table = 'webhook_deliveries',
    ) {
        $this->table = PostgresSchema::identifier($table);
    }

    /**
     * Returns the statements creating the table.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $name = PostgresSchema::unqualified($this->table);
        $due = PostgresSchema::identifier($name . '_due');
        $dead = PostgresSchema::identifier($name . '_dead');

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$this->table} (
                    id              TEXT PRIMARY KEY,
                    subscription_id TEXT NOT NULL,
                    event_type      TEXT NOT NULL,
                    body            TEXT NOT NULL,
                    status          TEXT NOT NULL,
                    attempts        INTEGER NOT NULL DEFAULT 0,
                    next_attempt_at TIMESTAMPTZ NOT NULL,
                    last_status     SMALLINT,
                    last_error      TEXT,
                    created_at      TIMESTAMPTZ NOT NULL
                )
                SQL,
            "CREATE INDEX IF NOT EXISTS {$due} ON {$this->table} (next_attempt_at) WHERE status = 'pending'",
            "CREATE INDEX IF NOT EXISTS {$dead} ON {$this->table} (created_at DESC) WHERE status = 'dead'",
        ];
    }

    /**
     * Deletes the deliveries acknowledged before a date and returns how many there were.
     */
    public function purgeDelivered(DateTimeImmutable $before): int
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE status = 'delivered' AND created_at < ?");
        $statement->execute([self::format($before)]);

        return $statement->rowCount();
    }

    #[Override]
    public function enqueue(OutgoingDelivery ...$deliveries): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->table} (" . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
        );

        foreach ($deliveries as $delivery) {
            $insert->execute([
                $delivery->id,
                $delivery->subscriptionId,
                $delivery->eventType,
                $delivery->body,
                $delivery->status->value,
                $delivery->attempts,
                self::format($delivery->nextAttemptAt),
                $delivery->lastStatus,
                $delivery->lastError,
                self::format($delivery->createdAt),
            ]);
        }
    }

    #[Override]
    public function reserve(DateTimeImmutable $now, int $limit, DateTimeImmutable $reservedUntil): array
    {
        $columns = implode(', ', array_map(static fn(string $column): string => 'd.' . $column, explode(', ', self::COLUMNS)));
        $statement = $this->pdo->prepare(<<<SQL
            WITH due AS (
                SELECT id, next_attempt_at AS due_at FROM {$this->table}
                WHERE status = 'pending' AND next_attempt_at <= :now
                ORDER BY next_attempt_at, id
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            )
            UPDATE {$this->table} AS d SET attempts = d.attempts + 1, next_attempt_at = :until
            FROM due WHERE d.id = due.id
            RETURNING {$columns}, to_char(due.due_at AT TIME ZONE 'UTC', 'YYYYMMDDHH24MISSUS') AS due_at
            SQL);
        $statement->bindValue('until', self::format($reservedUntil));
        $statement->bindValue('now', self::format($now));
        $statement->bindValue('limit', max(0, $limit), PDO::PARAM_INT);
        $statement->execute();
        $reserved = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delivery = self::hydrate($row);
            $dueAt = is_array($row) && is_string($row['due_at'] ?? null) ? $row['due_at'] : '';
            $reserved[$dueAt . ' ' . $delivery->id] = $delivery;
        }

        // UPDATE … RETURNING gives the rows in no particular order: oldest first is restored here.
        ksort($reserved, SORT_STRING);

        return array_values($reserved);
    }

    #[Override]
    public function delivered(string $id, int $status): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET status = 'delivered', last_status = ?, last_error = NULL WHERE id = ?")
            ->execute([$status, $id]);
    }

    #[Override]
    public function retryAt(string $id, DateTimeImmutable $at, ?int $status, string $error): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET next_attempt_at = ?, last_status = ?, last_error = ? WHERE id = ?")
            ->execute([self::format($at), $status, $error, $id]);
    }

    #[Override]
    public function dead(string $id, ?int $status, string $error): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET status = 'dead', last_status = ?, last_error = ? WHERE id = ?")
            ->execute([$status, $error, $id]);
    }

    #[Override]
    public function deadLetters(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE status = 'dead' ORDER BY created_at DESC, id LIMIT ?",
        );
        $statement->bindValue(1, max(0, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(self::hydrate(...), array_values($statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    #[Override]
    public function requeue(string $id, DateTimeImmutable $at): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = 'pending', attempts = 0, next_attempt_at = ? WHERE id = ? AND status = 'dead'",
        );
        $statement->execute([self::format($at), $id]);

        return $statement->rowCount() === 1;
    }

    private static function hydrate(mixed $row): OutgoingDelivery
    {
        $row = is_array($row) ? $row : [];
        $string = static fn(string $column): string => is_string($row[$column] ?? null) ? $row[$column] : '';

        return new OutgoingDelivery(
            $string('id'),
            $string('subscription_id'),
            $string('event_type'),
            $string('body'),
            new DateTimeImmutable($string('created_at')),
            new DateTimeImmutable($string('next_attempt_at')),
            DeliveryStatus::from($string('status')),
            is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : 0,
            is_numeric($row['last_status'] ?? null) ? (int) $row['last_status'] : null,
            is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
        );
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
