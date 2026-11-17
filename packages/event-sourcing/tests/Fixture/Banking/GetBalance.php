<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\Query;

final readonly class GetBalance implements Query
{
    public function __construct(
        public AccountId $accountId,
    ) {}
}
