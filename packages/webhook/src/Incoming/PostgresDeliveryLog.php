<?php

declare(strict_types=1);

namespace Acta\Webhook\Incoming;

use Acta\EventSourcing\Postgres\PostgresSchema;
use DateTimeImmutable;
use DateTimeInterface;
use Override;
use PDO;

/**
 * Delivery log in a PostgreSQL table, shared by every process receiving webhooks.
 *
 * Given the connection the commands write through, and a transaction manager on the
 * receiver, the record of a delivery commits together with its effects: a webhook is
 * then handled exactly once, even if the process dies halfway.
 */
final readonly class PostgresDeliveryLog implements DeliveryLog
{
    private string $table;

    /**
     * @param PDO    $pdo   a connection to PostgreSQL, with exceptions as error mode
     * @param string $table name of the table, a plain SQL identifier, optionally schema-qualified
     */
    public function __construct(
        private PDO $pdo,
        string $table = 'webhook_received',
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
        $index = PostgresSchema::identifier(PostgresSchema::unqualified($this->table) . '_expires_at');

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$this->table} (
                    source      TEXT NOT NULL,
                    delivery_id TEXT NOT NULL,
                    received_at TIMESTAMPTZ NOT NULL,
                    expires_at  TIMESTAMPTZ NOT NULL,
                    PRIMARY KEY (source, delivery_id)
                )
                SQL,
            "CREATE INDEX IF NOT EXISTS {$index} ON {$this->table} (expires_at)",
        ];
    }

    /**
     * Deletes the records expired at the given time and returns how many there were.
     */
    public function purge(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at <= ?");
        $statement->execute([self::format($now)]);

        return $statement->rowCount();
    }

    #[Override]
    public function claim(string $source, string $deliveryId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        $this->pdo
            ->prepare("DELETE FROM {$this->table} WHERE source = ? AND delivery_id = ? AND expires_at <= ?")
            ->execute([$source, $deliveryId, self::format($now)]);

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->table} (source, delivery_id, received_at, expires_at) VALUES (?, ?, ?, ?) "
            . 'ON CONFLICT (source, delivery_id) DO NOTHING',
        );
        $insert->execute([$source, $deliveryId, self::format($now), self::format($expiresAt)]);

        return $insert->rowCount() === 1;
    }

    #[Override]
    public function release(string $source, string $deliveryId): void
    {
        $this->pdo
            ->prepare("DELETE FROM {$this->table} WHERE source = ? AND delivery_id = ?")
            ->execute([$source, $deliveryId]);
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
