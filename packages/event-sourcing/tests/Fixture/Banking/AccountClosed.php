<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use Acta\EventSourcing\Serializer\SerializablePayload;
use Override;

/** Event that controls its own stored representation. */
#[MessageName('banking.account-closed')]
final readonly class AccountClosed implements DomainEvent, SerializablePayload
{
    public function __construct(
        public AccountId $accountId,
        public string $reason,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return ['account' => $this->accountId->value, 'why' => $this->reason];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(AccountId::fromString($payload['account']), $payload['why']);
    }
}
