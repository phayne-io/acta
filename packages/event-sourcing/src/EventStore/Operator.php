<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

/**
 * Comparison operators available in a {@see MetadataMatcher}.
 */
enum Operator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LowerThan = '<';
    case LowerThanOrEqual = '<=';

    /** The metadata value is one of the given list. */
    case In = 'in';

    /** The metadata value is none of the given list. */
    case NotIn = 'not in';

    /** The metadata value, a string, matches a POSIX-compatible regular expression. */
    case Regex = 'regex';

    /**
     * Applies the operator in PHP, with the semantics the PostgreSQL adapter implements
     * in SQL: a missing metadata key behaves like `null`, and ordering comparisons only
     * hold between two numbers or two strings.
     */
    public function test(mixed $actual, mixed $expected): bool
    {
        return match ($this) {
            self::Equals => self::same($actual, $expected),
            self::NotEquals => !self::same($actual, $expected),
            self::GreaterThan => self::comparable($actual, $expected) && $actual > $expected,
            self::GreaterThanOrEqual => self::comparable($actual, $expected) && $actual >= $expected,
            self::LowerThan => self::comparable($actual, $expected) && $actual < $expected,
            self::LowerThanOrEqual => self::comparable($actual, $expected) && $actual <= $expected,
            self::In => array_any((array) $expected, static fn(mixed $item): bool => self::same($actual, $item)),
            self::NotIn => !array_any((array) $expected, static fn(mixed $item): bool => self::same($actual, $item)),
            self::Regex => is_string($actual) && is_string($expected)
                && preg_match('~' . str_replace('~', '\~', $expected) . '~u', $actual) === 1,
        };
    }

    private static function same(mixed $actual, mixed $expected): bool
    {
        if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
            return $actual == $expected;
        }

        return $actual === $expected;
    }

    private static function comparable(mixed $actual, mixed $expected): bool
    {
        return ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected)))
            || (is_string($actual) && is_string($expected));
    }
}
