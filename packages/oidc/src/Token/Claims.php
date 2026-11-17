<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

/**
 * Claims of a token, or of a UserInfo response.
 */
final readonly class Claims
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public array $values,
    ) {}

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->values[$name] ?? $default;
    }

    /**
     * Returns a claim when it is a string, null otherwise.
     */
    public function string(string $name): ?string
    {
        $value = $this->values[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Returns a claim holding a timestamp or another whole number, null otherwise.
     */
    public function int(string $name): ?int
    {
        $value = $this->values[$name] ?? null;

        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    /**
     * Reads a claim through nested objects, such as `realm_access.roles`.
     *
     * A claim whose own name holds dots is found first.
     */
    public function path(string $path): mixed
    {
        if (array_key_exists($path, $this->values)) {
            return $this->values[$path];
        }

        $value = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Returns the strings a claim holds: the items of an array, or the words of a
     * space-separated string, which is how OAuth 2.0 writes scopes.
     *
     * @return list<string>
     */
    public function strings(string $path): array
    {
        $value = $this->path($path);

        if (is_string($value)) {
            return array_values(array_filter(explode(' ', $value), static fn(string $word): bool => $word !== ''));
        }

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
