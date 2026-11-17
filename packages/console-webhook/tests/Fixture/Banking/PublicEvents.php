<?php

declare(strict_types=1);

namespace Acta\ConsoleWebhook\Tests\Fixture\Banking;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\Webhook\Outgoing\IntegrationEvent;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use Override;

/**
 * What the bank tells its subscribers, in its public vocabulary.
 */
final readonly class PublicEvents implements IntegrationEventMapper
{
    #[Override]
    public function map(RecordedEvent $event): ?IntegrationEvent
    {
        $message = $event->message;

        return match (true) {
            $message instanceof AccountOpened => new IntegrationEvent('account.opened', [
                'accountId' => $message->accountId,
                'owner' => $message->owner,
            ]),
            $message instanceof MoneyDeposited => new IntegrationEvent('account.credited', [
                'accountId' => $message->accountId,
                'amount' => $message->money->getAmount(),
                'currency' => $message->money->getCurrency()->getCode(),
            ]),
            default => null,
        };
    }
}
