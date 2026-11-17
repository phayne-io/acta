<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Message\Envelope;

/**
 * Port deciding whether a message may be handled.
 *
 * It takes the place of Prooph's `AuthorizationService` and route guard. The caller's
 * identity is expected in the metadata of the envelope, where the driving adapter put
 * it.
 */
interface Authorizer
{
    public function isGranted(Envelope $envelope): bool;
}
