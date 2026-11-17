<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * JSON pointers (RFC 6901) into the contract.
 *
 * @internal
 */
final class JsonPointer
{
    /**
     * Appends reference tokens to a pointer, escaping them.
     */
    public static function append(string $pointer, string|int ...$tokens): string
    {
        foreach ($tokens as $token) {
            $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $token);
        }

        return $pointer;
    }

    /**
     * Splits a pointer into its unescaped reference tokens.
     *
     * @return list<string>
     */
    public static function tokens(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        return array_map(
            static fn(string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            array_slice(explode('/', $pointer), 1),
        );
    }

    /**
     * Returns the pointer as a URI fragment, without the leading `#`.
     */
    public static function toFragment(string $pointer): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $pointer)));
    }

    /**
     * Returns the pointer of a local reference such as `#/components/schemas/Money`, or
     * null when the reference leaves the document.
     */
    public static function fromReference(string $reference): ?string
    {
        if ($reference === '#') {
            return '';
        }

        return str_starts_with($reference, '#/') ? rawurldecode(substr($reference, 1)) : null;
    }

    /**
     * Reads the value a pointer designates in a decoded document.
     *
     * @param array<mixed> $document
     * @param bool         $found    set to false when the pointer leads nowhere
     *
     * @param-out bool $found
     */
    public static function get(array $document, string $pointer, ?bool &$found = null): mixed
    {
        $found = true;
        $node = $document;

        foreach (self::tokens($pointer) as $token) {
            if (!is_array($node) || !array_key_exists($token, $node)) {
                $found = false;

                return null;
            }

            $node = $node[$token];
        }

        return $node;
    }
}
