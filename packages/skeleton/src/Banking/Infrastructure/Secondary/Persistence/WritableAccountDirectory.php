<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Secondary\Persistence;

use Acta\EventSourcing\Projection\ReadModel;
use App\Banking\Application\Query\AccountDirectory;
use App\Banking\Application\Query\AccountView;

/**
 * The read model of accounts as its projection sees it: something to write to.
 *
 * The application only knows {@see AccountDirectory}, which reads.
 */
interface WritableAccountDirectory extends AccountDirectory, ReadModel
{
    /**
     * Stores an account, replacing what was known of it.
     */
    public function save(AccountView $account): void;
}
