<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

/**
 * Publishes events to every interested listener, in the current process.
 *
 * An event may have no listener at all, which is not an error.
 */
interface EventBus
{
    /**
     * @param object ...$events events, or envelopes carrying them when their identifier
     *                          and metadata must be preserved
     */
    public function publish(object ...$events): void;
}
