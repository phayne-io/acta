<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Acta\Http\Handler\OperationHandlers;
use Acta\Http\OpenApi\Contract;
use Acta\Http\Testing\ContractAssertions;
use App\Tests\Support\TestApplication;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the application and its OpenAPI contract in agreement.
 */
#[CoversNothing]
final class ContractTest extends TestCase
{
    public function testTheContractIsValid(): void
    {
        ContractAssertions::assertContractIsValid(TestApplication::boot()->get(Contract::class));
    }

    /**
     * An operation without a handler would answer 501; a handler without an operation
     * would never be called.
     */
    public function testEveryOperationHasAHandlerAndEveryHandlerAnOperation(): void
    {
        $app = TestApplication::boot();

        ContractAssertions::assertContractIsImplemented($app->get(Contract::class), $app->get(OperationHandlers::class));
    }

    /**
     * A new operation is protected unless its author decided otherwise, in the contract.
     */
    public function testEveryOperationStatesTheScopesItRequires(): void
    {
        foreach (TestApplication::boot()->get(Contract::class)->operations as $operation) {
            $scopes = array_merge(...array_map(array_values(...), $operation->security), ...[[]]);

            self::assertNotSame([], array_merge(...$scopes, ...[[]]), sprintf('The "%s" operation requires no scope.', $operation->id));
        }
    }
}
