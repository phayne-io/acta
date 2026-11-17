<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

/**
 * The three kinds of messages that travel through the buses.
 */
enum MessageKind: string
{
    case Command = 'command';
    case Query = 'query';
    case Event = 'event';

    /**
     * Infers the kind of a message from its marker interface. Anything that is neither a
     * {@see Command} nor a {@see Query} is treated as an event.
     */
    public static function of(object $message): self
    {
        return match (true) {
            $message instanceof Command => self::Command,
            $message instanceof Query => self::Query,
            default => self::Event,
        };
    }
}
