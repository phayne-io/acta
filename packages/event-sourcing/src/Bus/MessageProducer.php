<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Message\Envelope;

/**
 * Secondary port sending a message to another process: a queue, a topic, a worker.
 *
 * Adapters typically turn the envelope into a
 * {@see \Acta\EventSourcing\Serializer\SerializedMessage} and publish it. The consumer
 * rebuilds the envelope and dispatches it on its own bus.
 */
interface MessageProducer
{
    public function produce(Envelope $envelope): void;
}
