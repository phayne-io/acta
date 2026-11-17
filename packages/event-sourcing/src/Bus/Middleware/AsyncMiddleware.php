<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus\Middleware;

use Acta\EventSourcing\Bus\AsyncMessage;
use Acta\EventSourcing\Bus\MessageProducer;
use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;
use Closure;
use Override;

/**
 * Routes {@see AsyncMessage}s to a message producer the first time they are dispatched.
 *
 * It works like Prooph's `AsyncSwitchMessageRouter`: the message is flagged in its
 * metadata before being produced, so when the consumer dispatches it again on a bus
 * using the same middleware, it goes through to its handler.
 */
final readonly class AsyncMiddleware implements Middleware
{
    public function __construct(
        private MessageProducer $producer,
    ) {}

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        if (!$envelope->message instanceof AsyncMessage || $envelope->metadata->get(Metadata::HANDLED_ASYNC) === true) {
            return $next($envelope);
        }

        $this->producer->produce($envelope->withAddedMetadata(Metadata::HANDLED_ASYNC, true));

        return null;
    }
}
