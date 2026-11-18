<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Fixture;

use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\Message\Command;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;
use Closure;
use Override;

/**
 * Command bus keeping what it is given, and optionally failing.
 */
final class RecordingCommandBus implements CommandBus
{
    /** @var list<array{Command|Envelope, Metadata|null}> */
    public array $dispatched = [];

    /** @var (Closure(Command|Envelope): void)|null called on each dispatch, to simulate a handler */
    public ?Closure $handler = null;

    #[Override]
    public function dispatch(Command|Envelope $command, ?Metadata $metadata = null): void
    {
        if ($this->handler !== null) {
            ($this->handler)($command);
        }

        $this->dispatched[] = [$command, $metadata];
    }

    /**
     * @return list<Command|Envelope>
     */
    public function commands(): array
    {
        return array_column($this->dispatched, 0);
    }
}
