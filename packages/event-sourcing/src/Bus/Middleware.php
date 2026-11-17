<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Message\Envelope;
use Closure;

/**
 * A step wrapped around the handling of a message.
 *
 * Middlewares replace the plugin and action-event system of Prooph's service bus: what
 * was a listener attached to `initialize`, `route`, `invoke-handler` or `finalize` is
 * now code placed before or after the call to `$next`.
 */
interface Middleware
{
    /**
     * @param Closure(Envelope): mixed $next the rest of the pipeline; call it to continue,
     *                                       skip it to short-circuit the handling
     *
     * @return mixed the result of the pipeline, meaningful for queries only
     */
    public function handle(Envelope $envelope, Closure $next): mixed;
}
