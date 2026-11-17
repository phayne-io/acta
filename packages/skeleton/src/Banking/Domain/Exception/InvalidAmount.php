<?php

declare(strict_types=1);

namespace App\Banking\Domain\Exception;

/**
 * A deposit or a withdrawal was not of a positive amount.
 */
final class InvalidAmount extends BankingRuleViolated
{
    public static function notPositive(string $operation): self
    {
        return new self(sprintf('A %s must be of a positive amount.', $operation));
    }
}
