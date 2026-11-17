<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture;

use Acta\EventSourcing\Projection\ReadModel;
use Override;
use PDO;

/**
 * Read model stored in a PostgreSQL table, used to check that a projection batch and its
 * checkpoint commit together.
 */
final readonly class SqlBalances implements ReadModel
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function open(string $accountId, string $owner): void
    {
        $this->pdo
            ->prepare('INSERT INTO test_balances (account_id, owner, balance) VALUES (?, ?, 0)')
            ->execute([$accountId, $owner]);
    }

    public function add(string $accountId, int $amount): void
    {
        $this->pdo
            ->prepare('UPDATE test_balances SET balance = balance + ? WHERE account_id = ?')
            ->execute([$amount, $accountId]);
    }

    public function balanceOf(string $accountId): ?int
    {
        $statement = $this->pdo->prepare('SELECT balance FROM test_balances WHERE account_id = ?');
        $statement->execute([$accountId]);
        $balance = $statement->fetchColumn();

        return $balance === false ? null : (int) $balance;
    }

    #[Override]
    public function init(): void
    {
        $this->pdo->exec(
            'CREATE TABLE test_balances (account_id TEXT PRIMARY KEY, owner TEXT NOT NULL, balance BIGINT NOT NULL)',
        );
    }

    #[Override]
    public function isInitialized(): bool
    {
        $statement = $this->pdo->prepare("SELECT to_regclass('test_balances') IS NOT NULL");
        $statement->execute();

        return $statement->fetchColumn() === true;
    }

    #[Override]
    public function reset(): void
    {
        $this->pdo->exec('TRUNCATE test_balances');
    }

    #[Override]
    public function delete(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS test_balances');
    }

    #[Override]
    public function persist(): void {}
}
