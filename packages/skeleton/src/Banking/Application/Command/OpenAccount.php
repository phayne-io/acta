<?php

declare(strict_types=1);

namespace App\Banking\Application\Command;

use Acta\EventSourcing\Message\Command;
use App\Banking\Domain\AccountId;
use Money\Currency;

/**
 * Opens an account.
 *
 * The identifier is chosen by the caller, which lets a primary adapter answer with it
 * without waiting for anything.
 */
final readonly class OpenAccount implements Command
{
    public function __construct(
        public AccountId $accountId,
        public string $owner,
        public Currency $currency = new Currency('EUR'),
    ) {}
}
