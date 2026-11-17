<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Postgres;

use Acta\EventSourcing\Exception\SerializationFailed;
use JsonException;
use PDO;
use PDOStatement;

/**
 * Typed access to the rows PDO returns, which are arrays of unknown values.
 *
 * @internal
 */
final class Row
{
    /**
     * Runs a statement and returns its rows, an empty list for statements returning none.
     *
     * @param list<scalar|null> $parameters
     *
     * @return list<array<mixed>>
     */
    public static function all(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return self::rows($statement);
    }

    /**
     * @return list<array<mixed>>
     */
    public static function rows(PDOStatement $statement): array
    {
        $rows = [];

        if ($statement->columnCount() === 0) {
            return $rows;
        }

        while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed> $row
     *
     * @throws SerializationFailed when the column does not hold an integer
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        throw self::unexpected($column, 'an integer', $value);
    }

    /**
     * @param array<mixed> $row
     *
     * @throws SerializationFailed when the column does not hold a string
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : throw self::unexpected($column, 'a string', $value);
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<mixed>
     *
     * @throws SerializationFailed when the column does not hold a JSON object or array
     */
    public static function json(array $row, string $column): array
    {
        try {
            $value = json_decode(self::string($row, $column), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SerializationFailed(
                sprintf('Column "%s" does not hold valid JSON: %s', $column, $exception->getMessage()),
                previous: $exception,
            );
        }

        return is_array($value) ? $value : throw self::unexpected($column, 'a JSON object', $value);
    }

    /**
     * Same as {@see json()} for columns holding a JSON object, whose keys are strings.
     *
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     *
     * @throws SerializationFailed
     */
    public static function jsonObject(array $row, string $column): array
    {
        $object = [];

        foreach (self::json($row, $column) as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /**
     * Encodes a value as JSON; an empty array is stored as an object rather than a list.
     */
    public static function encode(mixed $value): string
    {
        if ($value === []) {
            return '{}';
        }

        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function unexpected(string $column, string $expected, mixed $value): SerializationFailed
    {
        return new SerializationFailed(sprintf(
            'Column "%s" should hold %s, got %s.',
            $column,
            $expected,
            get_debug_type($value),
        ));
    }
}
