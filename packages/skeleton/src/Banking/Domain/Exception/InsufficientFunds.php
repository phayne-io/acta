<?php

declare(strict_types=1);

namespace App\Banking\Domain\Exception;

use Money\Money;

/**
 * A withdrawal asked for more than the account holds.
 */
final class InsufficientFunds extends BankingRuleViolated
{
    public static function for(Money $requested, Money $available): self
    {
        return new self(sprintf(
            'The account holds %s %s, which does not cover a withdrawal of %s.',
            $available->getAmount(),
            $available->getCurrency(),
            $requested->getAmount(),
        ));
    }
}
