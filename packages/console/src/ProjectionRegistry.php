<?php

declare(strict_types=1);

namespace Acta\Console;

use Acta\EventSourcing\Projection\PersistentProjection;
use Closure;
use InvalidArgumentException;

/**
 * The projections of the application, by name, for the `projection:*` commands.
 *
 * A projection is registered as the closure that builds it, so that nothing is created,
 * and no read model touched, before a command asks for it:
 *
 *     $registry->add('balances', fn() => $projections
 *         ->readModelProjection('balances', $balances)
 *         ->fromCategory('account')
 *         ->when([...]));
 */
final class ProjectionRegistry
{
    /** @var array<string, Closure(): PersistentProjection> */
    private array $factories = [];

    /**
     * @param Closure(): PersistentProjection $factory builds the projection, ready to run
     */
    public function add(string $name, Closure $factory): static
    {
        $this->factories[$name] = $factory;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @throws InvalidArgumentException when no projection has this name
     */
    public function get(string $name): PersistentProjection
    {
        $factory = $this->factories[$name] ?? throw new InvalidArgumentException(sprintf(
            'No projection is named "%s". Known projections: %s.',
            $name,
            implode(', ', $this->names()) ?: 'none',
        ));

        return $factory();
    }
}
