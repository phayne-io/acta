<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Http;

use App\Banking\Application\Query\AccountView;

/**
 * The wire format of an account: the `Account` schema of the contract.
 */
final class AccountResource
{
    /**
     * @return array{id: string, owner: string, balance: array{amount: string, currency: string}, version: int}
     */
    public static function from(AccountView $account): array
    {
        return [
            'id' => $account->id,
            'owner' => $account->owner,
            'balance' => MoneyFormat::encode($account->balance),
            'version' => $account->version,
        ];
    }
}
