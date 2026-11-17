<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Body an operation accepts.
 */
final readonly class RequestBody
{
    /**
     * @param array<string, MediaType> $content accepted content, keyed by media type
     */
    public function __construct(
        public bool $required,
        public array $content,
    ) {}
}
