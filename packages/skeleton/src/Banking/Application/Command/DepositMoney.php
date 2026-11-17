<?php

declare(strict_types=1);

namespace App\Banking\Application\Command;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\Message\Command;
use App\Banking\Domain\AccountId;
use Money\Money;

/**
 * Adds money to an account.
 */
final readonly class DepositMoney implements Command
{
    /**
     * @param string|null          $reference       what the deposit refers to outside the bank
     * @param ExpectedVersion|null $expectedVersion version of the account the author of the command has
     *                                              seen, when they stated one
     */
    public function __construct(
        public AccountId $accountId,
        public Money $money,
        public ?string $reference = null,
        public ?ExpectedVersion $expectedVersion = null,
    ) {}
}
