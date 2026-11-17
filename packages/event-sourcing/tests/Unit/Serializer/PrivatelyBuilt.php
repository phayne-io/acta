<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Serializer;

/**
 * Value object that can only be created through a named constructor.
 */
final readonly class PrivatelyBuilt
{
    private function __construct(
        public string $label,
        public int $weight,
    ) {}

    public static function of(string $label, int $weight): self
    {
        return new self($label, $weight);
    }
}
