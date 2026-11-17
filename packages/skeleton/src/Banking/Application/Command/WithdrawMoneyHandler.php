<?php

declare(strict_types=1);

namespace App\Banking\Application\Command;

use Acta\EventSourcing\Bus\Handles;
use App\Banking\Domain\AccountRepository;
use App\Shared\OptimisticLock;

/**
 * Use case: withdraw money from an account.
 */
final readonly class WithdrawMoneyHandler
{
    public function __construct(
        private AccountRepository $accounts,
    ) {}

    #[Handles]
    public function __invoke(WithdrawMoney $command): void
    {
        $account = $this->accounts->get($command->accountId);
        OptimisticLock::assert($account, $command->expectedVersion);

        $account->withdraw($command->money);

        $this->accounts->save($account);
    }
}
