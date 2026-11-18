<?php

declare(strict_types=1);

namespace Acta\Console\Tests\Fixture\Banking;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\Webhook\Outgoing\IntegrationEvent;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use Override;

final readonly class PublicEvents implements IntegrationEventMapper
{
    #[Override]
    public function map(RecordedEvent $event): ?IntegrationEvent
    {
        $message = $event->message;

        return $message instanceof AccountOpened
            ? new IntegrationEvent('account.opened', ['accountId' => $message->accountId, 'owner' => $message->owner])
            : null;
    }
}
