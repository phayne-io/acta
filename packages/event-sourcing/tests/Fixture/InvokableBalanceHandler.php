<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture;

use Acta\EventSourcing\Bus\Handles;
use Acta\EventSourcing\Tests\Fixture\Banking\GetBalance;

/**
 * Invokable handler declared at class level, resolved lazily from a container in the tests.
 */
#[Handles]
final class InvokableBalanceHandler
{
    public function __invoke(GetBalance $query): int
    {
        return 99;
    }
}
