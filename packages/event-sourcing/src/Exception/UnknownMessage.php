<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown when a stored message name cannot be mapped back to a PHP class.
 */
final class UnknownMessage extends RuntimeException implements ActaException {}
