<?php

declare(strict_types=1);

namespace Acta\Webhook\Incoming;

use Acta\EventSourcing\Message\Command;
use Acta\Webhook\Exception\UnprocessableWebhook;

/**
 * Translates the webhooks of one sender into commands of the application.
 *
 * This is the anti-corruption layer: the vocabulary of the sender stops here. A
 * translator holds no business rule; it only says what the application is being asked
 * to do, if anything.
 */
interface WebhookTranslator
{
    /**
     * Returns the commands a webhook gives rise to; none for a webhook the application
     * has no use for, which is then acknowledged and forgotten.
     *
     * @return iterable<Command>
     *
     * @throws UnprocessableWebhook when the webhook is authentic but cannot be understood
     */
    public function translate(IncomingWebhook $webhook): iterable;
}
