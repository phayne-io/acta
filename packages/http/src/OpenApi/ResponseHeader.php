<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Header a response is declared to carry.
 */
final readonly class ResponseHeader
{
    /**
     * @param string|null $schemaPointer JSON pointer to the schema of the value, when the contract gives one
     */
    public function __construct(
        public string $name,
        public bool $required,
        public ?string $schemaPointer,
    ) {}
}
