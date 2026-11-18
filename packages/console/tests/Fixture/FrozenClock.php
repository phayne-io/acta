<?php

declare(strict_types=1);

namespace Acta\Console\Tests\Fixture;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Clock the tests move by hand.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        public DateTimeImmutable $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    ) {}

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
