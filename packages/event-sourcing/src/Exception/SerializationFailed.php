<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown when a message cannot be converted to or from its stored representation.
 */
final class SerializationFailed extends RuntimeException implements ActaException {}
