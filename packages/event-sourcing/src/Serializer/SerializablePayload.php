<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

/**
 * Lets a message, or a value object nested in a message, control its own representation.
 *
 * Implement it when the conventions of {@see ReflectionPayloadSerializer} do not fit:
 * union types, computed fields, a stored shape that differs from the constructor.
 */
interface SerializablePayload
{
    /**
     * @return array<string, mixed> JSON-compatible representation
     */
    public function toPayload(): array;

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static;
}
