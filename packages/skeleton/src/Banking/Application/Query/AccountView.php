<?php

declare(strict_types=1);

namespace App\Banking\Application\Query;

use Money\Money;

/**
 * An account as the read side knows it: what queries answer with.
 *
 * It is built from the events of the account by a projection, and may therefore be a
 * few moments behind the latest write.
 */
final readonly class AccountView
{
    /**
     * @param int $version version of the account this view reflects
     */
    public function __construct(
        public string $id,
        public string $owner,
        public Money $balance,
        public int $version,
    ) {}

    /**
     * Returns the view after a movement of money.
     *
     * @param Money $movement what was added to the account; negative when money left it
     * @param int   $version  version of the account once the movement is recorded
     */
    public function after(Money $movement, int $version): self
    {
        return clone($this, ['balance' => $this->balance->add($movement), 'version' => $version]);
    }
}
