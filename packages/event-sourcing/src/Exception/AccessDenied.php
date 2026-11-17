<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown by the authorization middleware when a message is not allowed to be handled.
 */
final class AccessDenied extends RuntimeException implements ActaException {}
