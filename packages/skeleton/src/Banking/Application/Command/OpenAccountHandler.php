<?php

declare(strict_types=1);

namespace App\Banking\Application\Command;

use Acta\EventSourcing\Bus\Handles;
use App\Banking\Domain\Account;
use App\Banking\Domain\AccountRepository;

/**
 * Use case: open an account.
 */
final readonly class OpenAccountHandler
{
    public function __construct(
        private AccountRepository $accounts,
    ) {}

    #[Handles]
    public function __invoke(OpenAccount $command): void
    {
        $this->accounts->save(Account::open($command->accountId, $command->owner, $command->currency));
    }
}
