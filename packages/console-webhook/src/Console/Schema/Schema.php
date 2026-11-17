<?php

declare(strict_types=1);

namespace Acta\Console\Schema;

/**
 * A set of tables the `es:migrate` command creates.
 */
interface Schema
{
    /**
     * Name shown when the schema is installed.
     */
    public function name(): string;

    /**
     * Returns idempotent statements: running them on an up-to-date database changes nothing.
     *
     * @return list<string>
     */
    public function statements(): array;
}
