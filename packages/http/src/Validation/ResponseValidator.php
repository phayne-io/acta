<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\MediaType;
use Acta\Http\OpenApi\Operation;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Checks that a response is one its operation declares: status, headers, media type and body.
 */
final readonly class ResponseValidator
{
    private SchemaValidator $schemas;

    private SchemaReader $reader;

    public function __construct(
        Contract $contract,
        ?SchemaValidator $schemas = null,
    ) {
        $this->schemas = $schemas ?? new SchemaValidator($contract);
        $this->reader = new SchemaReader($contract);
    }

    /**
     * @return list<Violation> empty when the response matches the contract
     */
    public function validate(ResponseInterface $response, Operation $operation): array
    {
        $status = $response->getStatusCode();
        $declared = $operation->responseFor($status);

        if ($declared === null) {
            return [new Violation('status', '', '', sprintf(
                'Status %d is not declared; the operation declares %s.',
                $status,
                implode(', ', array_keys($operation->responses)) ?: 'no response',
            ))];
        }

        $violations = [];

        foreach ($declared->headers as $header) {
            if (strtolower($header->name) === 'content-type') {
                continue;
            }

            if (!$response->hasHeader($header->name)) {
                if ($header->required) {
                    $violations[] = new Violation('header', $header->name, '', 'This header is required.');
                }

                continue;
            }

            if ($header->schemaPointer !== null) {
                $value = $this->reader->coerce($response->getHeaderLine($header->name), $this->reader->at($header->schemaPointer));

                foreach ($this->schemas->validate($value, $header->schemaPointer) as $pointer => $messages) {
                    foreach ($messages as $message) {
                        $violations[] = new Violation('header', $header->name, $pointer, $message);
                    }
                }
            }
        }

        $body = $response->getBody();
        $raw = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($declared->content === []) {
            if ($raw !== '') {
                $violations[] = new Violation('body', '', '', sprintf('No body is declared for status %s.', $declared->status));
            }

            return $violations;
        }

        if ($raw === '') {
            $violations[] = new Violation('body', '', '', sprintf('A body is declared for status %s.', $declared->status));

            return $violations;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $media = MediaType::match($declared->content, $contentType);

        if ($media === null) {
            $violations[] = new Violation('header', 'Content-Type', '', sprintf(
                '"%s" is not declared for status %s; expected %s.',
                $contentType,
                $declared->status,
                implode(', ', array_keys($declared->content)),
            ));

            return $violations;
        }

        if ($media->schemaPointer === null) {
            return $violations;
        }

        $value = $raw;

        if (MediaType::isJson(MediaType::essence($contentType))) {
            try {
                $value = json_decode($raw, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $violations[] = new Violation('body', '', '', 'The body is not valid JSON: ' . $exception->getMessage() . '.');

                return $violations;
            }
        }

        foreach ($this->schemas->validate($value, $media->schemaPointer) as $pointer => $messages) {
            foreach ($messages as $message) {
                $violations[] = new Violation('body', '', $pointer, $message);
            }
        }

        return $violations;
    }
}
