<?php

declare(strict_types=1);

namespace Acta\EventSourcing;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock returning the current time in UTC.
 *
 * It is the default clock of the library; inject another {@see ClockInterface} in tests
 * to control time.
 */
final readonly class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
