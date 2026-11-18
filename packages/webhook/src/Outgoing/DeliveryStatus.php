<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

/**
 * Where an outgoing delivery stands.
 */
enum DeliveryStatus: string
{
    /** Waiting for its next attempt. */
    case Pending = 'pending';

    /** Acknowledged by the subscriber. */
    case Delivered = 'delivered';

    /** Given up: a dead letter, kept to be inspected and sent again by hand. */
    case Dead = 'dead';
}
