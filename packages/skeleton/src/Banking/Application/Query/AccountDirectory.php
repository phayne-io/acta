<?php

declare(strict_types=1);

namespace App\Banking\Application\Query;

use App\Banking\Domain\AccountId;

/**
 * Port: the read model the queries about accounts are answered from.
 */
interface AccountDirectory
{
    public function find(AccountId $id): ?AccountView;

    /**
     * Returns accounts ordered by identifier, which is their order of creation.
     *
     * @param string|null $owner only the accounts of this owner when given
     *
     * @return list<AccountView>
     */
    public function list(?string $owner = null, int $limit = 20): array;
}
