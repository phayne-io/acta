<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use Acta\EventSourcing\Serializer\ListOf;
use Money\Money;

#[MessageName('banking.statement-issued')]
final readonly class StatementIssued implements DomainEvent
{
    /**
     * @param list<Money>          $lines
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public AccountId $accountId,
        #[ListOf(Money::class)]
        public array $lines,
        public float $rate,
        public array $extra = [],
    ) {}
}
