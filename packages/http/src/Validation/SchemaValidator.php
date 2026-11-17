<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use Acta\Http\Exception\ContractError;
use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\JsonPointer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * Validates values against the schemas of a contract.
 *
 * OpenAPI 3.1 schemas are JSON Schema 2020-12: the work is delegated to
 * `opis/json-schema`, with the whole contract registered as one document so that
 * references such as `#/components/schemas/Money` resolve.
 */
final class SchemaValidator
{
    private const string BASE_URI = 'https://contract.acta.invalid/openapi.json';

    private readonly Validator $validator;

    private readonly ErrorFormatter $formatter;

    /**
     * @param Validator|null $validator a configured validator, to register custom formats or
     *                                  media types; a default one is created otherwise
     * @param int            $maxErrors how many errors are collected before giving up on a value
     */
    public function __construct(
        public readonly Contract $contract,
        ?Validator $validator = null,
        int $maxErrors = 20,
    ) {
        $this->validator = $validator ?? new Validator();
        $this->validator->setMaxErrors($maxErrors);
        $this->validator->resolver()?->registerRaw($contract->document, self::BASE_URI);
        $this->formatter = new ErrorFormatter();
    }

    /**
     * Validates a value against the schema found at a pointer of the contract.
     *
     * The value must be in the form `json_decode()` returns without the `associative`
     * flag; {@see self::toJson()} converts associative arrays.
     *
     * @return array<string, list<string>> messages keyed by the JSON pointer of the faulty
     *                                     value, the empty pointer being the value itself;
     *                                     empty when the value is valid
     *
     * @throws ContractError when the schema itself is invalid
     */
    public function validate(mixed $value, string $schemaPointer): array
    {
        try {
            $result = $this->validator->validate($value, self::BASE_URI . '#' . JsonPointer::toFragment($schemaPointer));
        } catch (SchemaException|RuntimeException $exception) {
            throw new ContractError('Invalid schema: ' . $exception->getMessage(), $schemaPointer, $exception);
        }

        $error = $result->error();

        if ($error === null) {
            return [];
        }

        $errors = [];

        foreach ($this->formatter->format($error, true) as $pointer => $messages) {
            $pointer = rtrim((string) $pointer, '/');

            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                $errors[$pointer][] = is_string($message) ? $message : 'The value is invalid.';
            }
        }

        return $errors;
    }

    /**
     * Converts a PHP value to the form JSON Schema validation expects: associative arrays
     * become objects, lists stay lists.
     *
     * @param bool $emptyArrayIsObject how to read an empty array, which is ambiguous
     */
    public static function toJson(mixed $value, bool $emptyArrayIsObject = false): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === [] ? !$emptyArrayIsObject : array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::toJson($item), $value);
        }

        $object = new stdClass();

        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::toJson($item);
        }

        return $object;
    }
}
