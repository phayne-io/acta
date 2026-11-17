<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

use Acta\EventSourcing\Exception\SerializationFailed;
use NoDiscard;

/**
 * A message in its stored form: names and arrays, no domain object.
 *
 * It is what an event store row or a queue message carries, and what an {@see Upcaster}
 * transforms before the message class is instantiated.
 */
final readonly class SerializedMessage
{
    /**
     * @param string               $id        message identifier (UUID)
     * @param string               $name      public message name
     * @param int                  $version   schema version of the payload
     * @param array<string, mixed> $payload   message data
     * @param array<string, mixed> $metadata  message metadata
     * @param string               $createdAt creation instant, RFC 3339 with microseconds
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $version,
        public array $payload,
        public array $metadata,
        public string $createdAt,
    ) {}

    /**
     * Returns a copy with a new payload and schema version, as an upcaster produces.
     *
     * @param array<string, mixed> $payload
     */
    #[NoDiscard]
    public function withPayload(array $payload, ?int $version = null): self
    {
        return clone($this, ['payload' => $payload, 'version' => $version ?? $this->version]);
    }

    /**
     * Returns a copy published under another name, for events that were renamed.
     */
    #[NoDiscard]
    public function withName(string $name): self
    {
        return clone($this, ['name' => $name]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public function withMetadata(array $metadata): self
    {
        return clone($this, ['metadata' => $metadata]);
    }

    /**
     * @return array{id: string, name: string, version: int, payload: array<string, mixed>, metadata: array<string, mixed>, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Rebuilds a message from the array produced by {@see toArray()}, typically after it
     * travelled through a queue as JSON.
     *
     * @param array<mixed> $data
     *
     * @throws SerializationFailed when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $version = $data['version'] ?? 1;
        $createdAt = $data['created_at'] ?? null;

        if (!is_string($id) || !is_string($name) || !is_int($version) || !is_string($createdAt)) {
            throw new SerializationFailed(
                'A serialized message needs a string "id", "name" and "created_at", and an integer "version".',
            );
        }

        return new self(
            $id,
            $name,
            $version,
            self::map($data['payload'] ?? [], 'payload'),
            self::map($data['metadata'] ?? [], 'metadata'),
            $createdAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new SerializationFailed(sprintf('The "%s" of a serialized message must be a map.', $field));
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
