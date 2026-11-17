<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Message\Command;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\Metadata;
use Closure;
use Override;

/**
 * Command bus running a pipeline of middlewares before calling the handler.
 *
 * A typical write-side stack, outermost first:
 *
 * ```php
 * new DefaultCommandBus(
 *     $handlers,
 *     $envelopes,
 *     $causation,                                   // correlation and causation ids
 *     new RetryOnConflictMiddleware(3, $transactions),
 *     new TransactionMiddleware($transactions),
 * );
 * ```
 */
final readonly class DefaultCommandBus implements CommandBus
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
    public function dispatch(Command|Envelope $command, ?Metadata $metadata = null): void
    {
        $this->envelopes->wrap($command, $metadata) |> $this->pipeline;
    }

    private function invokeHandler(Envelope $envelope): null
    {
        Pipeline::soleHandler($this->handlers, $envelope)($envelope->message, $envelope);

        return null;
    }
}
