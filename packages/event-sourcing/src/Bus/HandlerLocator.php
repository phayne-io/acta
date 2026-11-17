<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Message\Envelope;

/**
 * Finds the handlers of a message.
 *
 * It stands for the routers, the service locator plugin and the invoke strategies of
 * Prooph's service bus. {@see HandlerMap} is the provided implementation.
 */
interface HandlerLocator
{
    /**
     * @return iterable<callable(object, Envelope): mixed> handlers to call with the
     *                                                     message and its envelope
     */
    public function handlersFor(object $message): iterable;
}
