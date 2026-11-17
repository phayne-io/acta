<?php

declare(strict_types=1);

namespace App\Banking\Application\Query;

use Acta\EventSourcing\Message\Query;
use App\Banking\Domain\AccountId;

/**
 * Asks for one account. The answer is an {@see AccountView}, or `null` when there is none.
 */
final readonly class GetAccount implements Query
{
    public function __construct(
        public AccountId $accountId,
    ) {}
}
