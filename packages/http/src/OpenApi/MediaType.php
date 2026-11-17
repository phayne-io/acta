<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Content of a body for one media type.
 */
final readonly class MediaType
{
    /**
     * @param string      $name          media type or media type range, in lower case: `application/json`, `text/*`
     * @param string|null $schemaPointer JSON pointer to the schema of the content, when the contract gives one
     */
    public function __construct(
        public string $name,
        public ?string $schemaPointer,
    ) {}

    /**
     * Tells whether a media type is JSON: `application/json` or a `+json` structured syntax.
     */
    public static function isJson(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /**
     * Returns a `Content-Type` value without its parameters, in lower case.
     */
    public static function essence(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    /**
     * Finds the most specific declared content for a `Content-Type` value.
     *
     * @param array<string, self> $content declared content, keyed by media type
     */
    public static function match(array $content, string $contentType): ?self
    {
        $essence = self::essence($contentType);

        return $content[$essence]
            ?? $content[explode('/', $essence, 2)[0] . '/*']
            ?? $content['*/*']
            ?? null;
    }
}
