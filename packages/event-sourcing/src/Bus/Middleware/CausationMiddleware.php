<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus\Middleware;

use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Message\MetadataEnricher;
use Closure;
use Override;

/**
 * Traces which message caused which.
 *
 * Like Prooph's `CausationMetadataEnricher`, it is both a bus middleware and a metadata
 * enricher. As a middleware it remembers the message being handled and gives it a
 * correlation identifier if it has none. As an enricher it stamps every message created
 * meanwhile (the events an aggregate records, the commands a handler dispatches) with
 * the correlation identifier of that message and with its identifier and name as cause.
 *
 * It can also carry context from a message to what it produces: list the metadata keys
 * to propagate, typically the actor and the tenant set by the driving adapter.
 *
 * Register the same instance on the buses and on the {@see \Acta\EventSourcing\Message\EnvelopeFactory}.
 */
final class CausationMiddleware implements Middleware, MetadataEnricher
{
    /** @var list<Envelope> */
    private array $handling = [];

    /** @var list<string> */
    private readonly array $propagatedKeys;

    /**
     * @param string ...$propagatedKeys metadata keys copied from a message to the messages it causes,
     *                                  unless they already define them
     */
    public function __construct(string ...$propagatedKeys)
    {
        $this->propagatedKeys = array_values($propagatedKeys);
    }

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        if (!$envelope->metadata->has(Metadata::CORRELATION_ID)) {
            $envelope = $envelope->withAddedMetadata(Metadata::CORRELATION_ID, $envelope->id->value);
        }

        $this->handling[] = $envelope;

        try {
            return $next($envelope);
        } finally {
            array_pop($this->handling);
        }
    }

    #[Override]
    public function enrich(Envelope $envelope): Envelope
    {
        $cause = array_last($this->handling);

        if ($cause === null) {
            return $envelope;
        }

        $inherited = array_intersect_key($cause->metadata->values, array_flip($this->propagatedKeys));

        return $envelope->withMetadata(new Metadata($inherited)->merge($envelope->metadata)->merge([
            Metadata::CORRELATION_ID => $cause->metadata->get(Metadata::CORRELATION_ID, $cause->id->value),
            Metadata::CAUSATION_ID => $cause->id->value,
            Metadata::CAUSATION_NAME => $cause->name,
        ]));
    }
}
