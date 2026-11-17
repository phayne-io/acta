<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

use Acta\EventSourcing\SystemClock;
use Acta\EventSourcing\Uuid;
use Psr\Clock\ClockInterface;

/**
 * Wraps messages into envelopes: assigns the identifier, the name and the creation date,
 * then runs the metadata enrichers.
 *
 * The buses and the aggregate repository share one factory so that every message of the
 * application is stamped the same way.
 */
final readonly class EnvelopeFactory
{
    /** @var list<MetadataEnricher> */
    private array $enrichers;

    public function __construct(
        private MessageTypeMap $types = new MessageTypeMap(),
        private ClockInterface $clock = new SystemClock(),
        MetadataEnricher ...$enrichers,
    ) {
        $this->enrichers = array_values($enrichers);
    }

    /**
     * Returns the envelope of `$message`.
     *
     * A message that is already an envelope keeps its identifier and creation date; the
     * given metadata is merged into it and enrichers are not applied again.
     */
    public function wrap(object $message, ?Metadata $metadata = null): Envelope
    {
        if ($message instanceof Envelope) {
            return $metadata === null
                ? $message
                : $message->withMetadata($message->metadata->merge($metadata));
        }

        $now = $this->clock->now();
        $envelope = new Envelope(
            $message,
            Uuid::v7($now),
            $this->types->nameOf($message),
            $now,
            $metadata ?? new Metadata(),
        );

        foreach ($this->enrichers as $enricher) {
            $envelope = $enricher->enrich($envelope);
        }

        return $envelope;
    }
}
