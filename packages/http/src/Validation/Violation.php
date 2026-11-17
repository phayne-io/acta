<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

/**
 * One difference between a message and the contract.
 */
final readonly class Violation
{
    /**
     * @param string $in      where the faulty value is: `path`, `query`, `header`, `cookie`, `body` or `status`
     * @param string $name    name of the parameter or header; empty for the body
     * @param string $pointer JSON pointer to the faulty value inside the parameter or the body
     * @param string $message what is wrong
     */
    public function __construct(
        public string $in,
        public string $name,
        public string $pointer,
        public string $message,
    ) {}

    /**
     * Returns the entry of the `errors` member of a problem document.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter(
            ['in' => $this->in, 'name' => $this->name, 'pointer' => $this->pointer, 'detail' => $this->message],
            static fn(string $value): bool => $value !== '',
        );
    }

    /**
     * Returns the violation as one sentence, for logs and assertion messages.
     */
    public function describe(): string
    {
        $where = trim(sprintf('%s %s', $this->in, $this->name));

        return sprintf('[%s%s] %s', $where, $this->pointer === '' ? '' : ' ' . $this->pointer, $this->message);
    }
}
