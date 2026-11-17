<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use Throwable;

/**
 * Marker implemented by every exception thrown by the library.
 *
 * Catch it to handle any failure coming from Acta without catching unrelated errors.
 */
interface ActaException extends Throwable {}
