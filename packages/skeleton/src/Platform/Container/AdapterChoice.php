<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Psr\Container\ContainerInterface;

/**
 * A container factory that picks the adapter of a port according to `acta.persistence`.
 *
 * ```php
 * 'factories' => [
 *     WritableAccountDirectory::class => new AdapterChoice(
 *         memory: InMemoryAccountDirectory::class,
 *         postgres: PostgresAccountDirectory::class,
 *     ),
 * ],
 * ```
 *
 * The chosen class is fetched from the container, which builds it from its constructor.
 */
final readonly class AdapterChoice
{
    /**
     * @param class-string $memory   adapter that keeps its state in the process, for tests
     * @param class-string $postgres adapter that keeps its state in PostgreSQL
     */
    public function __construct(
        private string $memory,
        private string $postgres,
    ) {}

    public function __invoke(ContainerInterface $container): object
    {
        return Resolve::from($container, PersistenceFactories::inMemory($container) ? $this->memory : $this->postgres);
    }
}
