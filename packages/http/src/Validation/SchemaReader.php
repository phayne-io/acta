<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\JsonPointer;
use stdClass;

/**
 * Reads just enough of a schema to turn the strings of a URL or a header into typed
 * values before they are validated.
 *
 * It follows local references and looks into `allOf`, `anyOf` and `oneOf`. It never
 * decides whether a value is valid: that is the job of {@see SchemaValidator}.
 *
 * @internal
 */
final readonly class SchemaReader
{
    private const int MAX_DEPTH = 16;

    public function __construct(
        private Contract $contract,
    ) {}

    /**
     * Returns the value a pointer designates in the contract, or null.
     */
    public function at(?string $pointer): mixed
    {
        if ($pointer === null) {
            return null;
        }

        $node = $this->contract->document;

        foreach (JsonPointer::tokens($pointer) as $token) {
            $node = match (true) {
                $node instanceof stdClass && property_exists($node, $token) => $node->{$token},
                is_array($node) && array_key_exists($token, $node) => $node[$token],
                default => null,
            };

            if ($node === null) {
                return null;
            }
        }

        return $node;
    }

    /**
     * Returns the JSON types a schema allows; empty when it does not say.
     *
     * @return list<string>
     */
    public function types(mixed $schema): array
    {
        $types = [];

        foreach ($this->facets($schema) as $facet) {
            foreach ((array) ($facet->type ?? []) as $type) {
                if (is_string($type)) {
                    $types[$type] = $type;
                }
            }
        }

        return array_values($types);
    }

    /**
     * Returns the names of the properties a schema declares.
     *
     * @return list<string>
     */
    public function propertyNames(mixed $schema): array
    {
        $names = [];

        foreach ($this->facets($schema) as $facet) {
            if (($facet->properties ?? null) instanceof stdClass) {
                foreach (array_keys(get_object_vars($facet->properties)) as $name) {
                    $names[$name] = (string) $name;
                }
            }
        }

        return array_values($names);
    }

    /**
     * Returns the default value a schema declares.
     *
     * @param bool $found set to whether the schema declares one
     *
     * @param-out bool $found
     */
    public function default(mixed $schema, ?bool &$found = null): mixed
    {
        foreach ($this->facets($schema) as $facet) {
            if (property_exists($facet, 'default')) {
                $found = true;

                return json_decode((string) json_encode($facet->default), true);
            }
        }

        $found = false;

        return null;
    }

    /**
     * Converts strings to the scalar types the schema asks for, recursively.
     *
     * A string is left alone when the schema accepts strings or says nothing, and when
     * it does not look like the expected type: validation then reports it.
     */
    public function coerce(mixed $value, mixed $schema): mixed
    {
        if (is_string($value)) {
            return $this->coerceString($value, $this->types($schema));
        }

        if (!is_array($value)) {
            return $value;
        }

        $coerced = [];
        $list = array_is_list($value);

        foreach ($value as $key => $item) {
            $coerced[$key] = $this->coerce($item, $list ? $this->items($schema) : $this->property($schema, (string) $key));
        }

        return $coerced;
    }

    /**
     * @param list<string> $types
     */
    private function coerceString(string $value, array $types): mixed
    {
        if ($types === [] || in_array('string', $types, true)) {
            return $value;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return match (true) {
            $integer !== false && array_any($types, static fn(string $type): bool => $type === 'integer' || $type === 'number') => $integer,
            in_array('number', $types, true) && preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $value) === 1 => (float) $value,
            in_array('boolean', $types, true) && ($value === 'true' || $value === 'false') => $value === 'true',
            in_array('null', $types, true) && $value === '' => null,
            default => $value,
        };
    }

    private function items(mixed $schema): mixed
    {
        foreach ($this->facets($schema) as $facet) {
            if (isset($facet->items)) {
                return $facet->items;
            }
        }

        return null;
    }

    private function property(mixed $schema, string $name): mixed
    {
        foreach ($this->facets($schema) as $facet) {
            if (($facet->properties ?? null) instanceof stdClass && isset($facet->properties->{$name})) {
                return $facet->properties->{$name};
            }
        }

        foreach ($this->facets($schema) as $facet) {
            if (($facet->additionalProperties ?? null) instanceof stdClass) {
                return $facet->additionalProperties;
            }
        }

        return null;
    }

    /**
     * Yields a schema and the schemas it is composed of.
     *
     * @return iterable<stdClass>
     */
    private function facets(mixed $schema, int $depth = 0): iterable
    {
        if (!$schema instanceof stdClass || $depth > self::MAX_DEPTH) {
            return;
        }

        yield $schema;

        if (is_string($schema->{'$ref'} ?? null)) {
            yield from $this->facets($this->at(JsonPointer::fromReference($schema->{'$ref'})), $depth + 1);
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach (is_array($schema->{$keyword} ?? null) ? $schema->{$keyword} : [] as $member) {
                yield from $this->facets($member, $depth + 1);
            }
        }
    }
}
