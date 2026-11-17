<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Response an operation is declared to return.
 */
final readonly class Response
{
    /**
     * @param string                        $status  status code, range such as `4XX`, or `default`
     * @param array<string, MediaType>      $content declared content, keyed by media type; empty for a response without body
     * @param array<string, ResponseHeader> $headers declared headers, keyed by lower-case name
     */
    public function __construct(
        public string $status,
        public string $description,
        public array $content,
        public array $headers,
    ) {}
}
