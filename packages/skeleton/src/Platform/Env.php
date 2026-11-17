<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Reads the configuration the environment provides, as the twelve-factor app does.
 *
 * It is meant for the files of `config/autoload` and for the `ConfigProvider` classes;
 * the rest of the code reads {@see Settings}.
 */
final class Env
{
    /**
     * Returns a variable, or the default when it is unset or empty.
     */
    public static function string(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    public static function int(string $name, int $default = 0): int
    {
        return (int) self::string($name, (string) $default);
    }

    /**
     * Reads `1`, `true`, `yes` and `on` as true, anything else as false.
     */
    public static function bool(string $name, bool $default = false): bool
    {
        return filter_var(self::string($name, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Reads a comma-separated list.
     *
     * @return list<string>
     */
    public static function list(string $name): array
    {
        return self::string($name)
            |> (static fn(string $value): array => explode(',', $value))
            |> (static fn(array $items): array => array_map(trim(...), $items))
            |> (static fn(array $items): array => array_filter($items, static fn(string $item): bool => $item !== ''))
            |> array_values(...);
    }
}
