<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\Command;
use Money\Money;

final readonly class DepositMoney implements Command
{
    public function __construct(
        public string $accountId,
        public Money $money,
        public string $reference,
    ) {}
}
