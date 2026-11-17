<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\Command;

final readonly class OpenAccount implements Command
{
    public function __construct(
        public AccountId $accountId,
        public string $owner,
        public string $currency = 'EUR',
    ) {}
}
