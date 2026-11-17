<?php

declare(strict_types=1);

namespace Acta\Http\EventSourcing;

use Acta\EventSourcing\Exception\AccessDenied;
use Acta\EventSourcing\Exception\AggregateNotFound;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\Http\Exception\HttpException;
use Acta\Http\Problem\ExceptionMapper;
use Override;
use Throwable;

/**
 * Gives an HTTP status to the exceptions of `acta/event-sourcing`:
 *
 * - an aggregate that does not exist is a 404;
 * - a write based on a stale version of an aggregate is a 409;
 * - a command the authorizer refused is a 403.
 *
 * Requires `acta/event-sourcing`.
 */
final readonly class EventSourcingExceptionMapper implements ExceptionMapper
{
    #[Override]
    public function map(Throwable $exception): ?HttpException
    {
        return match (true) {
            $exception instanceof AggregateNotFound => HttpException::notFound('The resource does not exist.'),
            $exception instanceof ConcurrencyConflict => HttpException::conflict(
                'The resource was modified in the meantime. Read it again, then retry.',
                $exception,
            ),
            $exception instanceof AccessDenied => HttpException::forbidden(),
            default => null,
        };
    }
}
