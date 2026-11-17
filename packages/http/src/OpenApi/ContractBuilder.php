<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

use Acta\Http\Exception\ContractError;
use stdClass;

/**
 * Reads the operations out of a decoded OpenAPI 3.1 description.
 *
 * @internal
 */
final class ContractBuilder
{
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private const int MAX_REFERENCE_DEPTH = 32;

    /** @var array<string, string> operation identifier => pointer of its declaration */
    private array $seen = [];

    /**
     * @param array<mixed> $document the description, with JSON objects as associative arrays
     */
    public function __construct(
        private readonly array $document,
    ) {}

    public function build(stdClass $source): Contract
    {
        $openapi = self::string($this->document['openapi'] ?? null);

        if (!str_starts_with($openapi, '3.1.')) {
            throw new ContractError(sprintf(
                'Only OpenAPI 3.1 descriptions are supported, got %s.',
                $openapi === '' ? 'no "openapi" version' : '"' . $openapi . '"',
            ), '/openapi');
        }

        $info = self::map($this->document['info'] ?? null);
        $schemes = $this->securitySchemes();
        $operations = [];
        $webhooks = [];

        foreach (array_keys(self::map($this->document['paths'] ?? null)) as $path) {
            $path = (string) $path;
            if (!str_starts_with($path, '/')) {
                throw new ContractError(sprintf('Path "%s" must start with a slash.', $path), JsonPointer::append('/paths', $path));
            }

            foreach ($this->pathItem(JsonPointer::append('/paths', $path), $path, false, $schemes) as $operation) {
                $operations[$operation->id] = $operation;
            }
        }

        foreach (array_keys(self::map($this->document['webhooks'] ?? null)) as $name) {
            $name = (string) $name;
            foreach ($this->pathItem(JsonPointer::append('/webhooks', $name), $name, true, $schemes) as $operation) {
                $webhooks[$operation->id] = $operation;
            }
        }

        return new Contract(
            $source,
            $openapi,
            self::string($info['title'] ?? null),
            self::string($info['version'] ?? null),
            $operations,
            $webhooks,
            $schemes,
        );
    }

    /**
     * @param array<string, SecurityScheme> $schemes
     *
     * @return list<Operation>
     */
    private function pathItem(string $pointer, string $path, bool $isWebhook, array $schemes): array
    {
        [$item, $pointer] = $this->resolve($pointer);
        $shared = $this->parameters($item['parameters'] ?? null, $pointer . '/parameters');
        $operations = [];

        foreach (self::METHODS as $method) {
            if (!isset($item[$method])) {
                continue;
            }

            $operationPointer = $pointer . '/' . $method;
            $definition = self::map($item[$method]);
            $id = self::string($definition['operationId'] ?? null);

            if ($id === '') {
                throw new ContractError(
                    sprintf('Operation %s %s needs an "operationId": it is how handlers are attached.', strtoupper($method), $path),
                    $operationPointer,
                );
            }

            if (isset($this->seen[$id])) {
                throw new ContractError(
                    sprintf('The operationId "%s" is already used at #%s.', $id, $this->seen[$id]),
                    $operationPointer,
                );
            }

            $this->seen[$id] = $operationPointer;
            $parameters = [
                ...$shared,
                ...$this->parameters($definition['parameters'] ?? null, $operationPointer . '/parameters'),
            ];

            if (!$isWebhook) {
                $this->assertPathParameters($path, $parameters, $operationPointer);
            }

            $operations[] = new Operation(
                $id,
                strtoupper($method),
                $path,
                array_values($parameters),
                isset($definition['requestBody']) ? $this->requestBody($operationPointer . '/requestBody') : null,
                $this->responses($definition['responses'] ?? null, $operationPointer . '/responses'),
                $this->security($definition, $operationPointer, $schemes),
                array_values(array_filter(self::list($definition['tags'] ?? null), is_string(...))),
                isset($definition['summary']) ? self::string($definition['summary']) : null,
                ($definition['deprecated'] ?? false) === true,
                array_filter($definition, static fn(int|string $key): bool => str_starts_with((string) $key, 'x-'), ARRAY_FILTER_USE_KEY),
                $operationPointer,
                $isWebhook,
            );
        }

        return $operations;
    }

    /**
     * @return array<string, Parameter> keyed by location and name, so that an operation overrides its path item
     */
    private function parameters(mixed $definitions, string $pointer): array
    {
        $parameters = [];

        foreach (array_keys(self::list($definitions)) as $index) {
            [$definition, $resolved] = $this->resolve($pointer . '/' . $index);
            $name = self::string($definition['name'] ?? null);
            $in = ParameterLocation::tryFrom(self::string($definition['in'] ?? null));

            if ($name === '' || $in === null) {
                throw new ContractError('A parameter needs a "name" and an "in" among path, query, header and cookie.', $resolved);
            }

            $style = self::string($definition['style'] ?? null) ?: $in->defaultStyle();

            if (!in_array($style, $in->supportedStyles(), true)) {
                throw new ContractError(
                    sprintf('Style "%s" is not supported for the %s parameter "%s".', $style, $in->value, $name),
                    $resolved,
                );
            }

            $content = self::map($definition['content'] ?? null);
            $mediaType = array_key_first($content);
            $schemaPointer = match (true) {
                isset($definition['schema']) => $resolved . '/schema',
                $mediaType !== null && isset(self::map($content[$mediaType])['schema']) => JsonPointer::append($resolved . '/content', $mediaType) . '/schema',
                default => null,
            };
            $key = $in->value . ':' . ($in === ParameterLocation::Header ? strtolower($name) : $name);

            $parameters[$key] = new Parameter(
                $name,
                $in,
                ($definition['required'] ?? false) === true,
                $style,
                is_bool($definition['explode'] ?? null) ? $definition['explode'] : $style === 'form',
                $schemaPointer,
                $mediaType === null ? null : MediaType::essence($mediaType),
                $resolved,
            );
        }

        return $parameters;
    }

    /**
     * @param array<string, Parameter> $parameters
     */
    private function assertPathParameters(string $path, array $parameters, string $pointer): void
    {
        preg_match_all('/\{([^{}\/]+)\}/', $path, $matches);

        foreach ($matches[1] as $name) {
            $parameter = $parameters['path:' . $name] ?? null;

            if ($parameter === null || !$parameter->required) {
                throw new ContractError(
                    sprintf('Path "%s" uses "{%s}": declare it as a required path parameter.', $path, $name),
                    $pointer,
                );
            }
        }

        foreach ($parameters as $parameter) {
            if ($parameter->in === ParameterLocation::Path && !in_array($parameter->name, $matches[1], true)) {
                throw new ContractError(
                    sprintf('Path parameter "%s" does not appear in "%s".', $parameter->name, $path),
                    $parameter->pointer,
                );
            }
        }
    }

    private function requestBody(string $pointer): RequestBody
    {
        [$definition, $resolved] = $this->resolve($pointer);

        return new RequestBody(
            ($definition['required'] ?? false) === true,
            $this->content($definition['content'] ?? null, $resolved . '/content'),
        );
    }

    /**
     * @return array<string, MediaType>
     */
    private function content(mixed $definitions, string $pointer): array
    {
        $content = [];

        foreach (self::map($definitions) as $name => $definition) {
            $name = (string) $name;
            $essence = MediaType::essence($name);
            $content[$essence] = new MediaType(
                $essence,
                isset(self::map($definition)['schema']) ? JsonPointer::append($pointer, $name) . '/schema' : null,
            );
        }

        return $content;
    }

    /**
     * @return array<int|string, Response>
     */
    private function responses(mixed $definitions, string $pointer): array
    {
        $responses = [];

        foreach (array_keys(self::map($definitions)) as $status) {
            // PHP turns numeric keys such as "200" into integers.
            $status = (string) $status;
            [$definition, $resolved] = $this->resolve(JsonPointer::append($pointer, $status));
            $status = strtolower($status) === 'default' ? 'default' : strtoupper($status);

            if (preg_match('/^([1-5](\d\d|XX)|default)$/', $status) !== 1) {
                throw new ContractError(sprintf('"%s" is not a status code, a range such as 4XX, or "default".', $status), $resolved);
            }

            $headers = [];

            foreach (array_keys(self::map($definition['headers'] ?? null)) as $name) {
                $name = (string) $name;
                [$header, $headerPointer] = $this->resolve(JsonPointer::append($resolved . '/headers', $name));
                $headers[strtolower($name)] = new ResponseHeader(
                    $name,
                    ($header['required'] ?? false) === true,
                    isset($header['schema']) ? $headerPointer . '/schema' : null,
                );
            }

            $responses[$status] = new Response(
                $status,
                self::string($definition['description'] ?? null),
                $this->content($definition['content'] ?? null, $resolved . '/content'),
                $headers,
            );
        }

        return $responses;
    }

    /**
     * @param array<string, mixed>          $operation
     * @param array<string, SecurityScheme> $schemes
     *
     * @return list<array<string, list<string>>>
     */
    private function security(array $operation, string $pointer, array $schemes): array
    {
        $security = [];

        foreach (self::list($operation['security'] ?? $this->document['security'] ?? null) as $requirement) {
            $alternative = [];

            foreach (self::map($requirement) as $name => $scopes) {
                $name = (string) $name;
                if (!isset($schemes[$name])) {
                    throw new ContractError(sprintf('Security scheme "%s" is not declared in the components.', $name), $pointer);
                }

                $alternative[$name] = array_values(array_filter(self::list($scopes), is_string(...)));
            }

            $security[] = $alternative;
        }

        return $security;
    }

    /**
     * @return array<string, SecurityScheme>
     */
    private function securitySchemes(): array
    {
        $schemes = [];
        $pointer = '/components/securitySchemes';

        foreach (array_keys(self::map(self::map($this->document['components'] ?? null)['securitySchemes'] ?? null)) as $name) {
            $name = (string) $name;
            [$definition, $resolved] = $this->resolve(JsonPointer::append($pointer, $name));
            $type = self::string($definition['type'] ?? null);

            if ($type === '') {
                throw new ContractError(sprintf('Security scheme "%s" needs a "type".', $name), $resolved);
            }

            $schemes[$name] = new SecurityScheme(
                $name,
                $type,
                isset($definition['scheme']) ? strtolower(self::string($definition['scheme'])) : null,
                $definition,
            );
        }

        return $schemes;
    }

    /**
     * Reads the object at a pointer, following local references.
     *
     * @return array{array<string, mixed>, string} the object and the pointer it was found at
     */
    private function resolve(string $pointer): array
    {
        for ($depth = 0; $depth < self::MAX_REFERENCE_DEPTH; ++$depth) {
            $node = JsonPointer::get($this->document, $pointer, $found);

            if (!$found || !is_array($node)) {
                throw new ContractError('Nothing can be read here: the reference leads nowhere or the value is not an object.', $pointer);
            }

            $node = self::map($node);

            if (!isset($node['$ref'])) {
                return [$node, $pointer];
            }

            $target = JsonPointer::fromReference(self::string($node['$ref']));

            if ($target === null) {
                throw new ContractError(
                    sprintf('Reference "%s" leaves the document. Bundle the contract into one file first.', self::string($node['$ref'])),
                    $pointer,
                );
            }

            $pointer = $target;
        }

        throw new ContractError('References form a cycle.', $pointer);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
