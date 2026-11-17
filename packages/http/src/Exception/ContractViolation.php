<?php

declare(strict_types=1);

namespace Acta\Http\Exception;

use Acta\Http\Validation\Violation;

/**
 * A request does not match what the contract declares for its operation.
 *
 * The problem document lists every violation in its `errors` member.
 */
final class ContractViolation extends HttpException
{
    /**
     * @param non-empty-list<Violation> $violations
     * @param int                       $status     status of the response, 422 by default
     */
    public function __construct(public readonly array $violations, int $status = 422)
    {
        parent::__construct(
            $status,
            count($violations) === 1
                ? 'The request does not match the contract: 1 error.'
                : sprintf('The request does not match the contract: %d errors.', count($violations)),
            extensions: ['errors' => array_map(static fn(Violation $violation): array => $violation->toArray(), $violations)],
        );
    }
}
