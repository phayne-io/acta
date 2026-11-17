<?php

declare(strict_types=1);

namespace Acta\Http\Idempotency;

use Acta\Http\Exception\ContractError;
use DateTimeImmutable;
use DateTimeInterface;
use Override;
use PDO;

/**
 * Idempotency store in a PostgreSQL table, shared by every process serving the API.
 *
 * Create the table with {@see self::install()} or copy {@see self::statements()} into
 * your migrations. Expired rows are removed by {@see self::purge()}, to be called from
 * a scheduled task.
 */
final readonly class PostgresIdempotencyStore implements IdempotencyStore
{
    /**
     * @param PDO    $pdo   a connection to PostgreSQL, with exceptions as error mode
     * @param string $table name of the table, a plain SQL identifier
     *
     * @throws ContractError when the table name is not a plain identifier
     */
    public function __construct(
        private PDO $pdo,
        private string $table = 'http_idempotency',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $table) !== 1) {
            throw new ContractError(sprintf('"%s" is not a plain SQL identifier.', $table));
        }
    }

    /**
     * Returns the statements creating the table.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$this->table} (
                    key         TEXT PRIMARY KEY,
                    fingerprint TEXT NOT NULL,
                    status      SMALLINT,
                    headers     JSONB,
                    body        TEXT,
                    expires_at  TIMESTAMPTZ NOT NULL
                )
                SQL,
            "CREATE INDEX IF NOT EXISTS {$this->table}_expires_at ON {$this->table} (expires_at)",
        ];
    }

    public function install(): void
    {
        foreach ($this->statements() as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /**
     * Deletes the keys expired at the given time and returns how many there were.
     */
    public function purge(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at <= ?");
        $statement->execute([self::format($now)]);

        return $statement->rowCount();
    }

    #[Override]
    public function acquire(string $key, string $fingerprint, DateTimeImmutable $now, DateTimeImmutable $expiresAt): ?IdempotencyRecord
    {
        $this->pdo
            ->prepare("DELETE FROM {$this->table} WHERE key = ? AND expires_at <= ?")
            ->execute([$key, self::format($now)]);

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->table} (key, fingerprint, expires_at) VALUES (?, ?, ?) ON CONFLICT (key) DO NOTHING",
        );
        $insert->execute([$key, $fingerprint, self::format($expiresAt)]);

        if ($insert->rowCount() === 1) {
            return null;
        }

        $select = $this->pdo->prepare("SELECT fingerprint, status, headers, body FROM {$this->table} WHERE key = ?");
        $select->execute([$key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            // The key was released between the two statements: it is free again.
            return $this->acquire($key, $fingerprint, $now, $expiresAt);
        }

        $status = $row['status'];
        $headers = is_string($row['headers']) ? json_decode($row['headers'], true) : null;
        $body = is_string($row['body']) ? base64_decode($row['body'], true) : false;

        return new IdempotencyRecord(
            is_string($row['fingerprint']) ? $row['fingerprint'] : '',
            is_numeric($status) && is_array($headers) && $body !== false
                ? new StoredResponse((int) $status, self::headers($headers), $body)
                : null,
        );
    }

    #[Override]
    public function complete(string $key, StoredResponse $response): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET status = ?, headers = ?, body = ? WHERE key = ?")
            ->execute([
                $response->status,
                json_encode($response->headers, JSON_THROW_ON_ERROR),
                base64_encode($response->body),
                $key,
            ]);
    }

    #[Override]
    public function release(string $key): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE key = ? AND status IS NULL")->execute([$key]);
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::RFC3339_EXTENDED);
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, list<string>>
     */
    private static function headers(array $decoded): array
    {
        $headers = [];

        foreach ($decoded as $name => $values) {
            $headers[(string) $name] = array_values(array_filter(is_array($values) ? $values : [$values], is_string(...)));
        }

        return $headers;
    }
}
