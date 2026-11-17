<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

use Acta\Http\Exception\ContractError;
use Acta\Http\Exception\UnknownOperation;
use JsonException;
use stdClass;

/**
 * An OpenAPI 3.1 description, the source of truth of the HTTP adapter.
 *
 * Routes, parameters, bodies, responses and security requirements are all read from it.
 * Build one with {@see ContractLoader}.
 */
final readonly class Contract
{
    /**
     * @param stdClass                      $document        the whole description, with JSON objects as `stdClass`
     * @param string                        $openapi         version of the specification the description follows
     * @param array<string, Operation>      $operations      operations of `paths`, keyed by `operationId`
     * @param array<string, Operation>      $webhooks        operations of `webhooks`, keyed by `operationId`
     * @param array<string, SecurityScheme> $securitySchemes security schemes, keyed by name
     */
    public function __construct(
        public stdClass $document,
        public string $openapi,
        public string $title,
        public string $version,
        public array $operations,
        public array $webhooks,
        public array $securitySchemes,
    ) {}

    /**
     * Builds a contract from a decoded description.
     *
     * Prefer objects (`json_decode()` without `associative`): with arrays, an empty JSON
     * object cannot be told from an empty list.
     *
     * @param stdClass|array<string, mixed> $document
     *
     * @throws ContractError when the description is not a usable OpenAPI 3.1 document
     */
    public static function fromDocument(stdClass|array $document): self
    {
        try {
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $object = is_array($document) ? json_decode($json, flags: JSON_THROW_ON_ERROR) : $document;
            $array = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContractError('The contract cannot be represented as JSON: ' . $exception->getMessage(), previous: $exception);
        }

        if (!$object instanceof stdClass || !is_array($array)) {
            throw new ContractError('The contract must be a JSON object.');
        }

        return new ContractBuilder($array)->build($object);
    }

    public function hasOperation(string $operationId): bool
    {
        return isset($this->operations[$operationId]);
    }

    /**
     * @throws UnknownOperation
     */
    public function operation(string $operationId): Operation
    {
        return $this->operations[$operationId] ?? throw UnknownOperation::named($operationId);
    }

    /**
     * @throws UnknownOperation
     */
    public function webhook(string $operationId): Operation
    {
        return $this->webhooks[$operationId] ?? throw UnknownOperation::named($operationId);
    }
}
