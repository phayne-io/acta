<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown when a projection is started while another process holds its lock.
 */
final class ProjectionAlreadyRunning extends RuntimeException implements ActaException {}
