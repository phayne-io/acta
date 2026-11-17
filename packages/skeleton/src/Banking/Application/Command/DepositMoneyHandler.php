<?php

declare(strict_types=1);

namespace App\Banking\Application\Command;

use Acta\EventSourcing\Bus\Handles;
use App\Banking\Domain\AccountRepository;
use App\Shared\OptimisticLock;

/**
 * Use case: deposit money on an account.
 */
final readonly class DepositMoneyHandler
{
    public function __construct(
        private AccountRepository $accounts,
    ) {}

    #[Handles]
    public function __invoke(DepositMoney $command): void
    {
        $account = $this->accounts->get($command->accountId);
        OptimisticLock::assert($account, $command->expectedVersion);

        $account->deposit($command->money, $command->reference);

        $this->accounts->save($account);
    }
}
