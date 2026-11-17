<?php

declare(strict_types=1);

namespace Acta\Http\Testing;

use Acta\Http\Handler\OperationHandlers;
use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\ContractLinter;
use Acta\Http\OpenApi\LintIssue;
use Acta\Http\Validation\ResponseValidator;
use Acta\Http\Validation\Violation;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * PHPUnit assertions keeping an application and its contract in agreement.
 *
 * Requires `phpunit/phpunit`.
 */
final class ContractAssertions
{
    /**
     * Asserts that the linter finds nothing to say about a contract.
     */
    public static function assertContractIsValid(Contract $contract): void
    {
        Assert::assertSame(
            [],
            array_map(static fn(LintIssue $issue): string => $issue->describe(), ContractLinter::lint($contract)),
            'The contract has defects.',
        );
    }

    /**
     * Asserts that every operation has a handler and every handler an operation.
     */
    public static function assertContractIsImplemented(Contract $contract, OperationHandlers $handlers): void
    {
        Assert::assertSame(
            ['unhandled' => [], 'unknown' => []],
            $handlers->compare($contract),
            'Handlers and operations of the contract differ.',
        );
    }

    /**
     * Asserts that a response is one the contract declares for an operation.
     */
    public static function assertResponseMatchesContract(Contract $contract, string $operationId, ResponseInterface $response): void
    {
        $violations = new ResponseValidator($contract)->validate($response, $contract->operation($operationId));

        Assert::assertSame(
            [],
            array_map(static fn(Violation $violation): string => $violation->describe(), $violations),
            sprintf('The response does not match what the contract declares for "%s".', $operationId),
        );
    }
}
