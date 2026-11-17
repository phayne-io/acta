<?php

declare(strict_types=1);

namespace App\Banking\Domain\Exception;

use Money\Currency;

/**
 * An amount was given in a currency the account does not hold.
 */
final class CurrencyMismatch extends BankingRuleViolated
{
    public static function between(Currency $account, Currency $given): self
    {
        return new self(sprintf('The account holds %s, not %s.', $account, $given));
    }
}
