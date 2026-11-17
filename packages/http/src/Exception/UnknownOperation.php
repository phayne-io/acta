<?php

declare(strict_types=1);

namespace Acta\Http\Exception;

use LogicException;

/**
 * An operation identifier that the contract does not declare was asked for.
 */
final class UnknownOperation extends LogicException implements ActaHttpException
{
    public static function named(string $operationId): self
    {
        return new self(sprintf('The contract declares no operation named "%s".', $operationId));
    }
}
