<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

/**
 * Lifecycle state of a persistent projection.
 *
 * The three last states are requests: the {@see ProjectionManager} writes them and the
 * process running the projection carries them out the next time it looks, which is how
 * a projection is controlled from another process.
 */
enum ProjectionStatus: string
{
    /** Not running. */
    case Idle = 'idle';

    /** A process currently runs the projection. */
    case Running = 'running';

    /** The projection must stop after its current batch. */
    case Stopping = 'stopping';

    /** The projection must forget its state and start over from the first event. */
    case Resetting = 'resetting';

    /** The projection must be removed. */
    case Deleting = 'deleting';

    /** The projection must be removed, together with the events it emitted. */
    case DeletingWithEmittedEvents = 'deleting-with-emitted-events';
}
