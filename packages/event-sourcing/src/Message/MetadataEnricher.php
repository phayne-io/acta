<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

/**
 * Adds metadata to every message created through the {@see EnvelopeFactory}.
 *
 * It is the equivalent of Prooph's `MetadataEnricher` plugin: implement it to stamp
 * messages with the current user, tenant, trace identifier and so on.
 */
interface MetadataEnricher
{
    /**
     * Returns the envelope to use in place of `$envelope`.
     */
    public function enrich(Envelope $envelope): Envelope;
}
