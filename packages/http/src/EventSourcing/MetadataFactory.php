<?php

declare(strict_types=1);

namespace Acta\Http\EventSourcing;

use Acta\EventSourcing\Message\Metadata;
use Acta\Http\Trace\TraceContext;
use Closure;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the metadata of the messages a request gives rise to.
 *
 * The trace of the request becomes the correlation identifier, so that every event
 * recorded while handling it can be traced back to the HTTP call. Extractors add what
 * the application knows about the request, typically who makes it:
 *
 *     new MetadataFactory(['actor' => fn($request) => $request->getAttribute(Principal::class)?->subject])
 *
 * Requires `acta/event-sourcing`.
 */
final readonly class MetadataFactory
{
    /**
     * @param array<string, Closure(ServerRequestInterface): mixed> $extractors metadata key => how to read its
     *                                                                          value; null values are left out
     */
    public function __construct(
        private array $extractors = [],
    ) {}

    public function fromRequest(ServerRequestInterface $request): Metadata
    {
        $values = [];
        $trace = TraceContext::tryFrom($request);

        if ($trace !== null) {
            $values[Metadata::CORRELATION_ID] = $trace->traceId;
            $values['traceparent'] = $trace->toHeader();
        }

        foreach ($this->extractors as $key => $extract) {
            $value = $extract($request);

            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return new Metadata($values);
    }
}
