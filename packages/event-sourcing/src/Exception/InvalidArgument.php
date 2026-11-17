<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use InvalidArgumentException;

/**
 * Thrown when a value object or a configuration value is given an invalid argument.
 */
final class InvalidArgument extends InvalidArgumentException implements ActaException {}
