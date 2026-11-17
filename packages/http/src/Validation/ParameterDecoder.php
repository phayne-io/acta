<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use Acta\Http\OpenApi\MediaType;
use Acta\Http\OpenApi\Parameter;
use Acta\Http\OpenApi\ParameterLocation;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads parameters out of a request, following the serialization styles of OpenAPI.
 *
 * @internal
 */
final readonly class ParameterDecoder
{
    private const array DELIMITERS = ['form' => ',', 'simple' => ',', 'spaceDelimited' => ' ', 'pipeDelimited' => '|'];

    public function __construct(
        private SchemaReader $schemas,
    ) {}

    /**
     * Splits a query string into names and their values, in order.
     *
     * PHP's own parsing keeps only the last of repeated names, which is how OpenAPI
     * sends arrays by default.
     *
     * @return array<string, list<string>>
     */
    public static function parseQuery(string $query): array
    {
        $values = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = [...explode('=', $pair, 2), ''];
            $values[urldecode($name)][] = urldecode($value);
        }

        return $values;
    }

    /**
     * Reads one parameter, with the types its schema declares.
     *
     * @param array<string, string>       $pathParameters values captured by the router
     * @param array<string, list<string>> $query          result of {@see self::parseQuery()}
     * @param bool                        $found          set to whether the request carries the parameter
     *
     * @param-out bool $found
     */
    public function decode(
        Parameter $parameter,
        ServerRequestInterface $request,
        array $pathParameters,
        array $query,
        ?bool &$found = null,
    ): mixed {
        $schema = $this->schemas->at($parameter->schemaPointer);
        $types = $this->schemas->types($schema);
        $shape = match (true) {
            in_array('array', $types, true) => 'array',
            in_array('object', $types, true) => 'object',
            default => 'scalar',
        };

        $raw = match ($parameter->in) {
            ParameterLocation::Path => $pathParameters[$parameter->name] ?? null,
            ParameterLocation::Header => $request->hasHeader($parameter->name) ? $request->getHeaderLine($parameter->name) : null,
            ParameterLocation::Cookie => self::cookie($request, $parameter->name),
            ParameterLocation::Query => $this->fromQuery($parameter, $query, $shape, $schema),
        };

        $found = $raw !== null;

        if ($raw === null) {
            return null;
        }

        if (is_string($raw) && $parameter->mediaType !== null) {
            $decoded = MediaType::isJson($parameter->mediaType) ? json_decode($raw, true) : $raw;

            return $decoded ?? $raw;
        }

        if (is_string($raw) && $shape !== 'scalar') {
            $parts = $raw === '' ? [] : explode(self::DELIMITERS[$parameter->style] ?? ',', $raw);
            $parts = $parameter->in === ParameterLocation::Header ? array_map(trim(...), $parts) : $parts;
            $raw = $shape === 'array' ? $parts : self::pairs($parts, $parameter->explode && $parameter->style === 'simple');
        }

        return $this->schemas->coerce($raw, $schema);
    }

    /**
     * @param array<string, list<string>> $query
     *
     * @return string|array<mixed>|null
     */
    private function fromQuery(Parameter $parameter, array $query, string $shape, mixed $schema): string|array|null
    {
        $name = $parameter->name;

        if ($parameter->style === 'deepObject') {
            $object = [];

            foreach ($query as $key => $values) {
                if (preg_match('/^' . preg_quote($name, '/') . '\[([^\[\]]+)\]$/', (string) $key, $matches) === 1) {
                    $object[$matches[1]] = (string) array_last($values);
                }
            }

            return $object === [] ? null : $object;
        }

        if ($shape === 'object' && $parameter->explode && $parameter->mediaType === null) {
            $object = [];

            foreach ($this->schemas->propertyNames($schema) as $property) {
                if (isset($query[$property])) {
                    $object[$property] = (string) array_last($query[$property]);
                }
            }

            return $object === [] ? null : $object;
        }

        $values = [...($query[$name] ?? []), ...($shape === 'array' ? $query[$name . '[]'] ?? [] : [])];

        if ($values === []) {
            return null;
        }

        return $shape === 'array' && $parameter->explode && $parameter->mediaType === null ? $values : (string) array_last($values);
    }

    private static function cookie(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getCookieParams()[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Reads an object serialized as `a=1,b=2` when exploded, as `a,1,b,2` otherwise.
     *
     * @param list<string> $parts
     *
     * @return array<string, string>
     */
    private static function pairs(array $parts, bool $exploded): array
    {
        $object = [];

        if ($exploded) {
            foreach ($parts as $part) {
                [$key, $value] = [...explode('=', $part, 2), ''];
                $object[$key] = $value;
            }

            return $object;
        }

        foreach (array_chunk($parts, 2) as $pair) {
            $object[$pair[0]] = $pair[1] ?? '';
        }

        return $object;
    }
}
