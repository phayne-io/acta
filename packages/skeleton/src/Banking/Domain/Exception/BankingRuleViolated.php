<?php

declare(strict_types=1);

namespace App\Banking\Domain\Exception;

use DomainException;

/**
 * A rule of the bank was not respected: the request is understood and is refused.
 *
 * The primary adapters report every subclass the same way, a 422 over HTTP, so a new
 * rule needs nothing but its own exception.
 */
abstract class BankingRuleViolated extends DomainException {}
