<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Uuid;
use DateTimeImmutable;

/**
 * Converts envelopes to their stored form and back.
 *
 * It combines the three collaborators needed to persist or transport a message: the
 * {@see MessageTypeMap} for names, a {@see PayloadSerializer} for data and an optional
 * {@see Upcaster} applied when reading. Event store adapters and message producers use
 * it; the domain never does.
 */
final readonly class MessageSerializer
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private MessageTypeMap $types = new MessageTypeMap(),
        private PayloadSerializer $payloads = new ReflectionPayloadSerializer(),
        private ?Upcaster $upcaster = null,
    ) {}

    public function serialize(Envelope $envelope): SerializedMessage
    {
        return new SerializedMessage(
            $envelope->id->value,
            $envelope->name,
            $this->types->versionOf($envelope->message),
            $this->payloads->serialize($envelope->message),
            $envelope->metadata->values,
            $envelope->createdAt->format(self::DATE_FORMAT),
        );
    }

    /**
     * Rebuilds the envelope(s) of a stored message, after upcasting.
     *
     * @return list<Envelope> usually one envelope; an upcaster may return none or several
     */
    public function deserialize(SerializedMessage $message): array
    {
        $envelopes = [];

        foreach ($this->upcaster?->upcast($message) ?? [$message] as $current) {
            $envelopes[] = new Envelope(
                $this->payloads->deserialize($this->types->classOf($current->name), $current->payload),
                Uuid::fromString($current->id),
                $current->name,
                new DateTimeImmutable($current->createdAt),
                new Metadata($current->metadata),
            );
        }

        return $envelopes;
    }
}
