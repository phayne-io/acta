<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

use Acta\EventSourcing\Exception\SerializationFailed;

/**
 * Port converting a message object to a JSON-compatible array and back.
 *
 * It plays the role of Prooph's `MessageConverter` and `MessageFactory` for the payload
 * part of a message. The default implementation is {@see ReflectionPayloadSerializer};
 * plug your own to delegate to an existing serializer.
 */
interface PayloadSerializer
{
    /**
     * @return array<string, mixed> JSON-compatible representation of the message
     *
     * @throws SerializationFailed
     */
    public function serialize(object $message): array;

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $payload
     *
     * @return T
     *
     * @throws SerializationFailed
     */
    public function deserialize(string $class, array $payload): object;
}
