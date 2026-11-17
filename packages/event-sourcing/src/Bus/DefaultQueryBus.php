<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Message\Query;
use Closure;
use Override;

/**
 * Query bus running a pipeline of middlewares before calling the handler, whose return
 * value is handed back to the caller.
 */
final readonly class DefaultQueryBus implements QueryBus
{
    /** @var Closure(Envelope): mixed */
    private Closure $pipeline;

    public function __construct(
        private HandlerLocator $handlers,
        private EnvelopeFactory $envelopes = new EnvelopeFactory(),
        Middleware ...$middlewares,
    ) {
        $this->pipeline = Pipeline::build(array_values($middlewares), $this->invokeHandler(...));
    }

    #[Override]
    public function ask(Query|Envelope $query, ?Metadata $metadata = null): mixed
    {
        return $this->envelopes->wrap($query, $metadata) |> $this->pipeline;
    }

    private function invokeHandler(Envelope $envelope): mixed
    {
        return Pipeline::soleHandler($this->handlers, $envelope)($envelope->message, $envelope);
    }
}
