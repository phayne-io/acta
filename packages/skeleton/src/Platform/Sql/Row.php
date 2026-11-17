<?php

declare(strict_types=1);

namespace App\Platform\Sql;

use PDO;
use UnexpectedValueException;

/**
 * A row of a query result, read with types.
 *
 * PDO returns arrays of unknown values. A read model adapter states here what it
 * expects of each column, once, instead of trusting the shape of every row.
 *
 * ```php
 * foreach (Row::select($pdo, 'SELECT id, owner FROM accounts WHERE owner = ?', [$owner]) as $row) {
 *     $row->string('id');
 * }
 * ```
 */
final readonly class Row
{
    /**
     * @param array<array-key, mixed> $columns
     */
    private function __construct(
        private array $columns,
    ) {}

    /**
     * Runs a query and returns its rows.
     *
     * @param list<scalar|null> $parameters values of the `?` placeholders
     *
     * @return list<self>
     */
    public static function select(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $columns) {
            $rows[] = new self(is_array($columns) ? $columns : []);
        }

        return $rows;
    }

    /**
     * @throws UnexpectedValueException when the column does not hold a string
     */
    public function string(string $column): string
    {
        $value = $this->columns[$column] ?? null;

        return is_string($value) ? $value : throw self::unexpected($column, 'a string');
    }

    /**
     * @return non-empty-string
     *
     * @throws UnexpectedValueException when the column does not hold a non-empty string
     */
    public function nonEmptyString(string $column): string
    {
        $value = $this->string($column);

        return $value !== '' ? $value : throw self::unexpected($column, 'a non-empty string');
    }

    /**
     * Reads a NUMERIC column, which PostgreSQL returns as a string to keep it exact.
     *
     * @return numeric-string
     *
     * @throws UnexpectedValueException when the column does not hold a number
     */
    public function numeric(string $column): string
    {
        $value = $this->columns[$column] ?? null;

        return is_string($value) && is_numeric($value) ? $value : throw self::unexpected($column, 'a number');
    }

    /**
     * @throws UnexpectedValueException when the column does not hold an integer
     */
    public function int(string $column): int
    {
        $value = $this->columns[$column] ?? null;

        return is_int($value) ? $value : throw self::unexpected($column, 'an integer');
    }

    public function bool(string $column): bool
    {
        return ($this->columns[$column] ?? null) === true;
    }

    private static function unexpected(string $column, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('The column "%s" is expected to hold %s.', $column, $expected));
    }
}
