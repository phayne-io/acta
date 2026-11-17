<?php

declare(strict_types=1);

namespace App\Banking\Domain\Exception;

/**
 * An account was opened in the name of nobody.
 */
final class InvalidOwner extends BankingRuleViolated
{
    public static function blank(): self
    {
        return new self('An account must be opened in the name of someone.');
    }
}
