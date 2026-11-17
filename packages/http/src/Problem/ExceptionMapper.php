<?php

declare(strict_types=1);

namespace Acta\Http\Problem;

use Acta\Http\Exception\HttpException;
use Throwable;

/**
 * Gives an HTTP meaning to exceptions that have none: those of the application layer,
 * of the domain or of a library.
 *
 * Mappers are how business errors reach the client without the domain knowing HTTP.
 */
interface ExceptionMapper
{
    /**
     * Returns the HTTP error to answer with, or null when this mapper does not know the exception.
     */
    public function map(Throwable $exception): ?HttpException;
}
