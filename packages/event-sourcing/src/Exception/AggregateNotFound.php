<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown when an aggregate that must exist has no event in its stream.
 */
final class AggregateNotFound extends RuntimeException implements ActaException {}
