<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown when a projection is addressed by a name that was never persisted.
 */
final class ProjectionNotFound extends RuntimeException implements ActaException {}
