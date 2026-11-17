<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown when a command or a query has no handler, or more than one.
 */
final class HandlerNotFound extends RuntimeException implements ActaException {}
