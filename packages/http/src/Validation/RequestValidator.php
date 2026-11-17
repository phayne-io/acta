<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use Acta\Http\Exception\ContractViolation;
use Acta\Http\Exception\HttpException;
use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\MediaType;
use Acta\Http\OpenApi\Operation;
use Acta\Http\OpenApi\ParameterLocation;
use Acta\Http\Routing\RouteMatch;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Checks a request against its operation and decodes what it carries.
 *
 * Path, query, header and cookie parameters are read with their serialization style and
 * given the types of their schemas; the body is decoded according to its media type.
 * Every violation is collected before the request is rejected.
 */
final readonly class RequestValidator
{
    /** Headers OpenAPI asks to ignore when declared as parameters. */
    private const array IGNORED_HEADERS = ['accept', 'content-type', 'authorization'];

    private SchemaValidator $schemas;

    private SchemaReader $reader;

    private ParameterDecoder $decoder;

    /**
     * @param int $violationStatus status of the response to a request that breaks the contract
     */
    public function __construct(
        Contract $contract,
        ?SchemaValidator $schemas = null,
        private int $violationStatus = 422,
    ) {
        $this->schemas = $schemas ?? new SchemaValidator($contract);
        $this->reader = new SchemaReader($contract);
        $this->decoder = new ParameterDecoder($this->reader);
    }

    /**
     * @throws ContractViolation when the request does not match the contract
     * @throws HttpException     400 for a body that cannot be decoded, 415 for a media type the operation does not accept
     */
    public function validate(ServerRequestInterface $request, RouteMatch $match): Input
    {
        $violations = [];
        $values = ['path' => [], 'query' => [], 'header' => [], 'cookie' => []];
        $query = ParameterDecoder::parseQuery($request->getUri()->getQuery());

        foreach ($match->operation->parameters as $parameter) {
            if ($parameter->in === ParameterLocation::Header && in_array(strtolower($parameter->name), self::IGNORED_HEADERS, true)) {
                continue;
            }

            $value = $this->decoder->decode($parameter, $request, $match->pathParameters, $query, $found);
            $schema = $this->reader->at($parameter->schemaPointer);

            if (!$found) {
                if ($parameter->required) {
                    $violations[] = new Violation($parameter->in->value, $parameter->name, '', 'This parameter is required.');

                    continue;
                }

                $value = $this->reader->default($schema, $hasDefault);

                if (!$hasDefault) {
                    continue;
                }
            }

            if ($parameter->schemaPointer !== null) {
                $json = SchemaValidator::toJson($value, in_array('object', $this->reader->types($schema), true));

                foreach ($this->schemas->validate($json, $parameter->schemaPointer) as $pointer => $messages) {
                    foreach ($messages as $message) {
                        $violations[] = new Violation($parameter->in->value, $parameter->name, $pointer, $message);
                    }
                }
            }

            $values[$parameter->in->value][$parameter->name] = $value;
        }

        [$body, $contentType] = $this->body($request, $match->operation, $violations);

        if ($violations !== []) {
            throw new ContractViolation($violations, $this->violationStatus);
        }

        return new Input($values['path'], $values['query'], $values['header'], $values['cookie'], $body, $contentType);
    }

    /**
     * @param list<Violation> $violations
     *
     * @return array{mixed, string|null} the decoded body and its media type
     */
    private function body(ServerRequestInterface $request, Operation $operation, array &$violations): array
    {
        $declared = $operation->requestBody;

        if ($declared === null) {
            return [null, null];
        }

        $raw = (string) $request->getBody();
        $contentType = $request->getHeaderLine('Content-Type');

        if ($raw === '') {
            if ($declared->required) {
                $violations[] = new Violation('body', '', '', 'A request body is required.');
            }

            return [null, null];
        }

        $media = MediaType::match($declared->content, $contentType);

        if ($media === null) {
            throw HttpException::unsupportedMediaType(sprintf(
                'This operation accepts %s.',
                implode(', ', array_keys($declared->content)),
            ));
        }

        $essence = MediaType::essence($contentType);

        if (MediaType::isJson($essence)) {
            try {
                $json = json_decode($raw, flags: JSON_THROW_ON_ERROR);
                $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw HttpException::badRequest('The body is not valid JSON: ' . $exception->getMessage() . '.', $exception);
            }
        } elseif ($essence === 'application/x-www-form-urlencoded' || $essence === 'multipart/form-data') {
            $fields = $essence === 'multipart/form-data'
                ? (array) $request->getParsedBody()
                : array_map(static fn(array $values): string => (string) array_last($values), ParameterDecoder::parseQuery($raw));
            $body = $this->reader->coerce($fields, $this->reader->at($media->schemaPointer));
            $json = SchemaValidator::toJson($body, true);
        } else {
            $body = $json = $raw;
        }

        if ($media->schemaPointer !== null) {
            foreach ($this->schemas->validate($json, $media->schemaPointer) as $pointer => $messages) {
                foreach ($messages as $message) {
                    $violations[] = new Violation('body', '', $pointer, $message);
                }
            }
        }

        return [$body, $essence];
    }
}
