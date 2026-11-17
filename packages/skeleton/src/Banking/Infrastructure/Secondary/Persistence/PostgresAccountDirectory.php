<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Secondary\Persistence;

use Acta\Console\Schema\Schema;
use App\Banking\Application\Query\AccountView;
use App\Banking\Domain\AccountId;
use App\Platform\Sql\Row;
use Money\Currency;
use Money\Money;
use Override;
use PDO;

/**
 * Secondary adapter: the read model of accounts as a PostgreSQL table.
 *
 * The table can be dropped at any time: `bin/console projection:rebuild banking_accounts`
 * fills it again from the events.
 */
final readonly class PostgresAccountDirectory implements WritableAccountDirectory, Schema
{
    private const string TABLE = 'banking_accounts';

    public function __construct(
        private PDO $pdo,
    ) {}

    #[Override]
    public function name(): string
    {
        return self::TABLE;
    }

    /**
     * Returns the statements creating the table, for `bin/console es:migrate`.
     *
     * @return list<string>
     */
    #[Override]
    public function statements(): array
    {
        return [
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS banking_accounts (
                    account_id UUID        PRIMARY KEY,
                    owner      TEXT        NOT NULL,
                    balance    NUMERIC     NOT NULL,
                    currency   VARCHAR(3)  NOT NULL,
                    version    INTEGER     NOT NULL
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS banking_accounts_owner ON banking_accounts (owner)',
        ];
    }

    #[Override]
    public function find(AccountId $id): ?AccountView
    {
        $rows = $this->select('WHERE account_id = ?', [$id->value]);

        return $rows[0] ?? null;
    }

    #[Override]
    public function list(?string $owner = null, int $limit = 20): array
    {
        return $owner === null
            ? $this->select('ORDER BY account_id LIMIT ?', [$limit])
            : $this->select('WHERE owner = ? ORDER BY account_id LIMIT ?', [$owner, $limit]);
    }

    #[Override]
    public function save(AccountView $account): void
    {
        $this->pdo
            ->prepare(<<<'SQL'
                INSERT INTO banking_accounts (account_id, owner, balance, currency, version)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (account_id) DO UPDATE
                    SET owner = EXCLUDED.owner, balance = EXCLUDED.balance, currency = EXCLUDED.currency, version = EXCLUDED.version
                SQL)
            ->execute([
                $account->id,
                $account->owner,
                $account->balance->getAmount(),
                $account->balance->getCurrency()->getCode(),
                $account->version,
            ]);
    }

    #[Override]
    public function init(): void
    {
        foreach ($this->statements() as $statement) {
            $this->pdo->exec($statement);
        }
    }

    #[Override]
    public function isInitialized(): bool
    {
        return Row::select($this->pdo, 'SELECT to_regclass(?) IS NOT NULL AS exists', [self::TABLE])[0]->bool('exists');
    }

    #[Override]
    public function reset(): void
    {
        $this->pdo->exec('TRUNCATE banking_accounts');
    }

    #[Override]
    public function delete(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS banking_accounts');
    }

    #[Override]
    public function persist(): void
    {
        // Every change is written as it happens, in the transaction of the projection.
    }

    /**
     * @param list<string|int> $parameters
     *
     * @return list<AccountView>
     */
    private function select(string $clause, array $parameters): array
    {
        return array_map(
            static fn(Row $row): AccountView => new AccountView(
                $row->string('account_id'),
                $row->string('owner'),
                new Money($row->numeric('balance'), new Currency($row->nonEmptyString('currency'))),
                $row->int('version'),
            ),
            Row::select($this->pdo, 'SELECT account_id, owner, balance::text AS balance, currency, version FROM banking_accounts ' . $clause, $parameters),
        );
    }
}
