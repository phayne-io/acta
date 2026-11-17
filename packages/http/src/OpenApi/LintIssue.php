<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * A defect found in a contract by {@see ContractLinter}.
 */
final readonly class LintIssue
{
    /**
     * @param string $pointer JSON pointer to the faulty part of the contract
     */
    public function __construct(
        public string $pointer,
        public string $message,
    ) {}

    public function describe(): string
    {
        return sprintf('#%s: %s', $this->pointer, $this->message);
    }
}
