<?php

declare(strict_types=1);

namespace App\Banking\Application\Command;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\Message\Command;
use App\Banking\Domain\AccountId;
use Money\Money;

/**
 * Takes money from an account.
 */
final readonly class WithdrawMoney implements Command
{
    /**
     * @param ExpectedVersion|null $expectedVersion version of the account the author of the command has
     *                                              seen, when they stated one
     */
    public function __construct(
        public AccountId $accountId,
        public Money $money,
        public ?ExpectedVersion $expectedVersion = null,
    ) {}
}
