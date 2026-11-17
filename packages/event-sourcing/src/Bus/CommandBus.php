<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Exception\HandlerNotFound;
use Acta\EventSourcing\Message\Command;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;

/**
 * Primary port of the write side: sends a command to its single handler.
 *
 * Driving adapters (HTTP handlers, console commands, webhook receivers) depend on this
 * interface only.
 */
interface CommandBus
{
    /**
     * @param Command|Envelope $command  the command, or an envelope already carrying one
     *                                   (for instance when it was received from a queue)
     * @param Metadata|null    $metadata context to attach to the command: actor, tenant...
     *
     * @throws HandlerNotFound when the command does not have exactly one handler
     */
    public function dispatch(Command|Envelope $command, ?Metadata $metadata = null): void;
}
