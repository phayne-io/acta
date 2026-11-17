<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

/**
 * What the application tells the outside world: a public event, distinct from the
 * domain event it was derived from.
 *
 * Domain events are internal and free to change; integration events are a contract
 * with subscribers, described in the `webhooks` section of the OpenAPI description.
 */
final readonly class IntegrationEvent
{
    /**
     * @param string               $type public name of the event, such as `account.opened`
     * @param array<string, mixed> $data what happened, as JSON-serializable values
     */
    public function __construct(
        public string $type,
        public array $data,
    ) {}
}
