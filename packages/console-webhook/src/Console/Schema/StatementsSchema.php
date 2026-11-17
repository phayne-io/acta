<?php

declare(strict_types=1);

namespace Acta\Console\Schema;

use Override;

/**
 * A schema given as its statements, which is what every Acta adapter exposes:
 *
 *     new StatementsSchema('event store', new PostgresSchema()->statements())
 *     new StatementsSchema('outgoing webhooks', $deliveryQueue->statements())
 */
final readonly class StatementsSchema implements Schema
{
    /**
     * @param list<string> $statements
     */
    public function __construct(
        private string $name,
        private array $statements,
    ) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function statements(): array
    {
        return $this->statements;
    }
}
