<?php

declare(strict_types=1);

namespace Acta\Oidc;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Clock reading the time of the system; the default wherever a PSR-20 clock is optional.
 */
final readonly class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
