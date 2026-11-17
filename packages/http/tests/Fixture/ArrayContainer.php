<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture;

use Override;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * PSR-11 container over an array, counting what it is asked for.
 */
final class ArrayContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $requested = [];

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(
        private readonly array $services,
    ) {}

    #[Override]
    public function get(string $id): mixed
    {
        $this->requested[] = $id;

        return $this->services[$id] ?? throw new RuntimeException(sprintf('No service named "%s".', $id));
    }

    #[Override]
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
