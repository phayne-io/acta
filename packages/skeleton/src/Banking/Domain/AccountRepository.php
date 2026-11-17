<?php

declare(strict_types=1);

namespace App\Banking\Domain;

/**
 * Port: where accounts are kept.
 *
 * The domain states what it needs; the adapter in `Infrastructure\Secondary` decides
 * that accounts are streams of events.
 */
interface AccountRepository
{
    /**
     * Returns an account as it stands now. Asking for an account that does not exist is
     * an error, which the adapter raises and the primary adapters report as "not found".
     */
    public function get(AccountId $id): Account;

    /**
     * Records what happened to an account since it was loaded. It fails when the
     * account changed in the meantime, which the primary adapters report as a conflict.
     */
    public function save(Account $account): void;
}
