<?php

declare(strict_types=1);

namespace App\Platform\Make;

use InvalidArgumentException;

/**
 * Derives the spellings the generators need from a name such as `IssueInvoice`.
 */
final class Naming
{
    /**
     * Returns the name in StudlyCaps, the spelling of classes.
     *
     * @throws InvalidArgumentException when the name cannot be a class name
     */
    public static function studly(string $name): string
    {
        $studly = preg_split('/[^A-Za-z0-9]+/', trim($name), flags: PREG_SPLIT_NO_EMPTY)
            |> (static fn(array|false $words): array => array_map(ucfirst(...), $words ?: []))
            |> (static fn(array $words): string => implode('', $words));

        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $studly) === 1
            ? $studly
            : throw new InvalidArgumentException(sprintf('"%s" cannot be turned into a class name: use letters and digits, starting with a letter.', $name));
    }

    /**
     * `IssueInvoice` → `issueInvoice`, the spelling of variables and of operation identifiers.
     */
    public static function camel(string $studly): string
    {
        return lcfirst($studly);
    }

    /**
     * `IssueInvoice` → `issue-invoice`, the spelling of URL segments and of console commands.
     */
    public static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $studly));
    }

    /**
     * `IssueInvoice` → `issue_invoice`, the spelling of tables and of stream categories.
     */
    public static function snake(string $studly): string
    {
        return str_replace('-', '_', self::kebab($studly));
    }

    /**
     * `IssueInvoice` → `issue invoice`, for sentences.
     */
    public static function words(string $studly): string
    {
        return str_replace('-', ' ', self::kebab($studly));
    }

    /**
     * A plain English plural, good enough for a first draft: `Invoice` → `Invoices`,
     * `Policy` → `Policies`, `Address` → `Addresses`.
     */
    public static function plural(string $studly): string
    {
        return match (true) {
            preg_match('/[^aeiou]y$/', $studly) === 1 => substr($studly, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/', $studly) === 1 => $studly . 'es',
            default => $studly . 's',
        };
    }
}
