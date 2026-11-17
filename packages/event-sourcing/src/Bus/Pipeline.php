<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Exception\HandlerNotFound;
use Acta\EventSourcing\Message\Envelope;
use Closure;

/**
 * Helpers shared by the three default buses.
 *
 * @internal
 */
final class Pipeline
{
    /**
     * Chains middlewares in front of a destination and returns the entry point.
     *
     * @param list<Middleware>         $middlewares outermost first
     * @param Closure(Envelope): mixed $destination
     *
     * @return Closure(Envelope): mixed
     */
    public static function build(array $middlewares, Closure $destination): Closure
    {
        $next = $destination;

        foreach (array_reverse($middlewares) as $middleware) {
            $next = static fn(Envelope $envelope): mixed => $middleware->handle($envelope, $next);
        }

        return $next;
    }

    /**
     * Returns the one handler of a command or a query.
     *
     * @return callable(object, Envelope): mixed
     *
     * @throws HandlerNotFound when there is no handler or more than one
     */
    public static function soleHandler(HandlerLocator $handlers, Envelope $envelope): callable
    {
        $found = [...$handlers->handlersFor($envelope->message)];

        return match (count($found)) {
            1 => $found[0],
            0 => throw new HandlerNotFound(sprintf('No handler is registered for "%s".', $envelope->name)),
            default => throw new HandlerNotFound(sprintf(
                '"%s" has %d handlers, a %s must have exactly one.',
                $envelope->name,
                count($found),
                $envelope->kind()->value,
            )),
        };
    }
}
