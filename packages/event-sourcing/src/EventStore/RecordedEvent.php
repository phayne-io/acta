<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;

/**
 * An event as the event store knows it: its envelope plus where it was written.
 *
 * Prooph kept this information in reserved metadata keys (`_aggregate_version`,
 * `_position`...). Here it is typed and separate from the metadata.
 */
final class RecordedEvent
{
    /** The domain event itself, shortcut for `$envelope->message`. */
    public object $message {
        get => $this->envelope->message;
    }

    /** Public name of the event, shortcut for `$envelope->name`. */
    public string $name {
        get => $this->envelope->name;
    }

    /** Metadata of the event, shortcut for `$envelope->metadata`. */
    public Metadata $metadata {
        get => $this->envelope->metadata;
    }

    /**
     * @param Envelope   $envelope      the event with its identifier, date and metadata
     * @param StreamName $stream        stream the event belongs to
     * @param int        $streamVersion 1-based rank of the event in its stream
     * @param Position   $position      place of the event in the global log
     */
    public function __construct(
        public readonly Envelope $envelope,
        public readonly StreamName $stream,
        public readonly int $streamVersion,
        public readonly Position $position,
    ) {}
}
