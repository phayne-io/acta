<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Bus\AsyncMessage;
use Acta\EventSourcing\Message\Command;

final readonly class SendWelcomeLetter implements Command, AsyncMessage
{
    public function __construct(
        public AccountId $accountId,
    ) {}
}
