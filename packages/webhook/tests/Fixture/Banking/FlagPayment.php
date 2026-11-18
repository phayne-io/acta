<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\Command;

final readonly class FlagPayment implements Command
{
    public function __construct(
        public string $reference,
        public string $reason,
    ) {}
}
