<?php

declare(strict_types=1);

namespace Acta\Http\Exception;

use Acta\Http\Validation\Violation;
use RuntimeException;

/**
 * A handler produced a response the contract does not describe.
 *
 * This is a defect of the application, reported as a server error.
 */
final class ResponseContractViolation extends RuntimeException implements ActaHttpException
{
    /**
     * @param non-empty-list<Violation> $violations
     */
    public function __construct(public readonly string $operationId, public readonly array $violations)
    {
        parent::__construct(sprintf(
            'The response of operation "%s" does not match the contract: %s',
            $operationId,
            implode(' ', array_map(static fn(Violation $violation): string => $violation->describe(), $violations)),
        ));
    }
}
