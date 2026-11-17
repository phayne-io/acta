<?php

declare(strict_types=1);

namespace Acta\Http\EventSourcing;

use Acta\EventSourcing\Exception\SerializationFailed;
use Acta\EventSourcing\Serializer\PayloadSerializer;
use Acta\EventSourcing\Serializer\ReflectionPayloadSerializer;
use Acta\Http\Validation\Input;

/**
 * Builds commands and queries from the validated input of a request.
 *
 * The fields of the body, the query parameters and the path parameters are matched to
 * the constructor parameters of the message by name, with the conventions of the
 * payload serializer: identifiers from strings, enums from their values, nested value
 * objects from JSON objects. A handler is then one line of translation:
 *
 *     $command = $this->messages->create(DepositMoney::class, Input::from($request));
 *
 * Requires `acta/event-sourcing`.
 */
final readonly class MessageFactory
{
    public function __construct(
        private PayloadSerializer $payloads = new ReflectionPayloadSerializer(),
    ) {}

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $overrides values that win over the input, such as the authenticated caller
     *
     * @return T
     *
     * @throws SerializationFailed when the input lacks a field the message needs: the
     *                             contract and the message disagree, which is a defect
     */
    public function create(string $class, Input $input, array $overrides = []): object
    {
        // The most trusted source wins: the path over the query, the query over the body.
        return $this->payloads->deserialize($class, [...$input->fields(), ...$input->query, ...$input->path, ...$overrides]);
    }
}
