<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Exception;

use RuntimeException;

/**
 * Thrown when a transaction is rolled back because one of its nested units of work failed.
 */
final class TransactionAborted extends RuntimeException implements ActaException {}
