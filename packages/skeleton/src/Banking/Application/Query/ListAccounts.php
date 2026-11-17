<?php

declare(strict_types=1);

namespace App\Banking\Application\Query;

use Acta\EventSourcing\Message\Query;

/**
 * Asks for accounts, optionally those of one owner. The answer is a list of {@see AccountView}.
 */
final readonly class ListAccounts implements Query
{
    public function __construct(
        public ?string $owner = null,
        public int $limit = 20,
    ) {}
}
