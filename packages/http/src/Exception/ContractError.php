<?php

declare(strict_types=1);

namespace Acta\Http\Exception;

use LogicException;
use Throwable;

/**
 * The OpenAPI contract, or the way the application is wired to it, is wrong.
 *
 * This is a defect to fix before deploying, never the fault of a client: it is reported
 * as a server error.
 */
final class ContractError extends LogicException implements ActaHttpException
{
    /**
     * @param string $pointer JSON pointer to the faulty part of the contract, when known
     */
    public function __construct(string $message, public readonly string $pointer = '', ?Throwable $previous = null)
    {
        parent::__construct($pointer === '' ? $message : sprintf('%s (at #%s)', $message, $pointer), previous: $previous);
    }
}
