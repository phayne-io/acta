<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\Exception\InvalidArgument;
use Closure;

/**
 * Tuning of a persistent projection.
 */
final readonly class ProjectionOptions
{
    /**
     * @param int                 $batchSize         number of events read and handled per transaction
     * @param int                 $sleepMicroseconds pause between two reads once the projection has caught up
     * @param bool                $dispatchSignals   calls `pcntl_signal_dispatch()` between batches, so a signal
     *                                               handler can stop the projection cleanly
     * @param Closure(int): mixed $wait              how to pause for the given number of microseconds; replace
     *                                               the default `usleep()` to wake up earlier, for instance on
     *                                               a database notification
     *
     * @throws InvalidArgument when the batch size or the pause is out of range
     */
    public function __construct(
        public int $batchSize = 1000,
        public int $sleepMicroseconds = 100_000,
        public bool $dispatchSignals = false,
        public Closure $wait = usleep(...),
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgument('The batch size of a projection must be at least 1.');
        }

        if ($sleepMicroseconds < 0) {
            throw new InvalidArgument('The pause of a projection cannot be negative.');
        }
    }
}
