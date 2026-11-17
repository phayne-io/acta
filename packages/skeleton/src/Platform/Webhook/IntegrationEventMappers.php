<?php

declare(strict_types=1);

namespace App\Platform\Webhook;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\Webhook\Outgoing\IntegrationEvent;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use Override;

/**
 * Asks each context in turn what a domain event means to the outside.
 *
 * Every context publishes its own events through its own mapper, declared under
 * `acta.integration_events`; the first one that recognises an event answers.
 */
final readonly class IntegrationEventMappers implements IntegrationEventMapper
{
    /** @var list<IntegrationEventMapper> */
    private array $mappers;

    public function __construct(IntegrationEventMapper ...$mappers)
    {
        $this->mappers = array_values($mappers);
    }

    #[Override]
    public function map(RecordedEvent $event): ?IntegrationEvent
    {
        foreach ($this->mappers as $mapper) {
            $mapped = $mapper->map($event);

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }
}
