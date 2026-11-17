<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use Acta\EventSourcing\EventStore\RecordedEvent;

/**
 * Decides which domain events are made public, and in which shape.
 *
 * Everything is private by default: an event leaves the application only because a
 * mapper says so, in the terms it chooses.
 */
interface IntegrationEventMapper
{
    /**
     * Returns the public event to send for a domain event, or null to send nothing.
     */
    public function map(RecordedEvent $event): ?IntegrationEvent;
}
