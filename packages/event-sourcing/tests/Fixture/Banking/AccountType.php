<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
}
