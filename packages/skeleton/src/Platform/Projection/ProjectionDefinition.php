<?php

declare(strict_types=1);

namespace App\Platform\Projection;

use Acta\EventSourcing\Projection\PersistentProjection;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;

/**
 * How a context describes one of its projections to the platform.
 *
 * A definition is declared under `acta.projections` in the `ConfigProvider` of its
 * context, keyed by the name of the projection. The platform then makes it available to
 * `projection:run`, `projection:rebuild` and `projection:status`.
 */
interface ProjectionDefinition
{
    /**
     * Builds the projection: which events it reads and what it does with them.
     *
     * @param string            $name    name the projection is registered under
     * @param ProjectionOptions $options how the platform wants projections to run; pass it on
     */
    public function build(ProjectionManager $projections, string $name, ProjectionOptions $options): PersistentProjection;
}
