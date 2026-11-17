<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

use Acta\Http\Exception\ContractError;
use Acta\Http\Validation\SchemaValidator;
use stdClass;

/**
 * Looks for defects in a contract before it reaches production.
 *
 * It checks what this package relies on, not the whole OpenAPI specification: that the
 * description loads, that every reference leads somewhere, that every schema used by an
 * operation can be read by the validator, and that operations declare their responses.
 * Run it in the test suite or from a console command.
 */
final class ContractLinter
{
    /**
     * Lints a contract file.
     *
     * @return list<LintIssue> empty when nothing was found
     */
    public static function lintFile(string $file): array
    {
        try {
            return self::lint(ContractLoader::load($file));
        } catch (ContractError $error) {
            return [new LintIssue($error->pointer, $error->getMessage())];
        }
    }

    /**
     * @return list<LintIssue> empty when nothing was found
     */
    public static function lint(Contract $contract): array
    {
        $issues = [];

        if ($contract->title === '' || $contract->version === '') {
            $issues[] = new LintIssue('/info', 'The description needs a title and a version.');
        }

        self::references($contract->document, $contract->document, '', $issues);

        foreach ($contract->securitySchemes as $scheme) {
            if ($scheme->type === 'http' && $scheme->scheme === null) {
                $issues[] = new LintIssue(
                    JsonPointer::append('/components/securitySchemes', $scheme->name),
                    'A security scheme of type "http" needs a "scheme", such as "bearer".',
                );
            }
        }

        $schemas = new SchemaValidator($contract);

        foreach ([...$contract->operations, ...$contract->webhooks] as $operation) {
            if ($operation->responses === []) {
                $issues[] = new LintIssue($operation->pointer . '/responses', sprintf('Operation "%s" declares no response.', $operation->id));
            }

            $pointers = array_map(static fn(Parameter $parameter): ?string => $parameter->schemaPointer, $operation->parameters);

            foreach ($operation->requestBody->content ?? [] as $media) {
                $pointers[] = $media->schemaPointer;
            }

            foreach ($operation->responses as $response) {
                if ($response->description === '') {
                    $issues[] = new LintIssue(
                        JsonPointer::append($operation->pointer . '/responses', $response->status),
                        'A response needs a description.',
                    );
                }

                foreach ([...$response->content, ...$response->headers] as $described) {
                    $pointers[] = $described->schemaPointer;
                }
            }

            foreach (array_unique(array_filter($pointers, is_string(...))) as $pointer) {
                try {
                    $schemas->validate(null, $pointer);
                } catch (ContractError $error) {
                    $issues[] = new LintIssue($pointer, $error->getPrevious()?->getMessage() ?? $error->getMessage());
                }
            }
        }

        return $issues;
    }

    /**
     * Checks that every `$ref` of the document designates something inside it.
     *
     * @param list<LintIssue> $issues
     */
    private static function references(stdClass $document, mixed $node, string $pointer, array &$issues): void
    {
        if (!is_array($node) && !$node instanceof stdClass) {
            return;
        }

        foreach ($node instanceof stdClass ? get_object_vars($node) : $node as $key => $value) {
            $child = JsonPointer::append($pointer, $key);

            if ($key !== '$ref' || !is_string($value)) {
                self::references($document, $value, $child, $issues);

                continue;
            }

            $target = JsonPointer::fromReference($value);

            if ($target === null) {
                $issues[] = new LintIssue($child, sprintf('Reference "%s" leaves the document: bundle the contract into one file.', $value));
            } elseif (!self::exists($document, $target)) {
                $issues[] = new LintIssue($child, sprintf('Reference "%s" leads nowhere.', $value));
            }
        }
    }

    private static function exists(stdClass $document, string $pointer): bool
    {
        $node = $document;

        foreach (JsonPointer::tokens($pointer) as $token) {
            if ($node instanceof stdClass && property_exists($node, $token)) {
                $node = $node->{$token};
            } elseif (is_array($node) && array_key_exists($token, $node)) {
                $node = $node[$token];
            } else {
                return false;
            }
        }

        return true;
    }
}
