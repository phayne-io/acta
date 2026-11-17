<?php

declare(strict_types=1);

namespace App\Banking\Application\Query;

use Acta\EventSourcing\Bus\Handles;

/**
 * Answers {@see GetAccount} from the read model.
 */
final readonly class GetAccountHandler
{
    public function __construct(
        private AccountDirectory $directory,
    ) {}

    #[Handles]
    public function __invoke(GetAccount $query): ?AccountView
    {
        return $this->directory->find($query->accountId);
    }
}
