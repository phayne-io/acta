<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Secondary\Persistence;

use App\Banking\Application\Query\AccountView;
use App\Banking\Domain\AccountId;
use Override;

/**
 * Secondary adapter: the read model of accounts kept in the process, for tests.
 */
final class InMemoryAccountDirectory implements WritableAccountDirectory
{
    /** @var array<string, AccountView> */
    private array $accounts = [];

    #[Override]
    public function find(AccountId $id): ?AccountView
    {
        return $this->accounts[$id->value] ?? null;
    }

    #[Override]
    public function list(?string $owner = null, int $limit = 20): array
    {
        $accounts = array_filter(
            $this->accounts,
            static fn(AccountView $account): bool => $owner === null || $account->owner === $owner,
        );
        ksort($accounts);

        return array_slice(array_values($accounts), 0, $limit);
    }

    #[Override]
    public function save(AccountView $account): void
    {
        $this->accounts[$account->id] = $account;
    }

    #[Override]
    public function init(): void {}

    #[Override]
    public function isInitialized(): bool
    {
        return true;
    }

    #[Override]
    public function reset(): void
    {
        $this->accounts = [];
    }

    #[Override]
    public function delete(): void
    {
        $this->accounts = [];
    }

    #[Override]
    public function persist(): void {}
}
