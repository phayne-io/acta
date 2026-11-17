<?php

declare(strict_types=1);

namespace App\Banking\Application\Query;

use Acta\EventSourcing\Bus\Handles;

/**
 * Answers {@see ListAccounts} from the read model.
 */
final readonly class ListAccountsHandler
{
    public function __construct(
        private AccountDirectory $directory,
    ) {}

    /**
     * @return list<AccountView>
     */
    #[Handles]
    public function __invoke(ListAccounts $query): array
    {
        return $this->directory->list($query->owner, $query->limit);
    }
}
