<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Exception\ListenerFailures;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Closure;
use Override;
use Throwable;

/**
 * Event bus calling every listener of an event, one after the other.
 *
 * By default the first failing listener stops the publication and its exception is
 * rethrown. With `$collectErrors`, every listener runs and the failures are reported
 * together in a {@see ListenerFailures}, like Prooph's listener exception collection
 * mode.
 */
final readonly class DefaultEventBus implements EventBus
{
    /** @var Closure(Envelope): mixed */
    private Closure $pipeline;

    public function __construct(
        private HandlerLocator $listeners,
        private EnvelopeFactory $envelopes = new EnvelopeFactory(),
        private bool $collectErrors = false,
        Middleware ...$middlewares,
    ) {
        $this->pipeline = Pipeline::build(array_values($middlewares), $this->invokeListeners(...));
    }

    #[Override]
    public function publish(object ...$events): void
    {
        foreach ($events as $event) {
            $this->envelopes->wrap($event) |> $this->pipeline;
        }
    }

    private function invokeListeners(Envelope $envelope): null
    {
        $failures = [];

        foreach ($this->listeners->handlersFor($envelope->message) as $listener) {
            try {
                $listener($envelope->message, $envelope);
            } catch (Throwable $failure) {
                if (!$this->collectErrors) {
                    throw $failure;
                }

                $failures[] = $failure;
            }
        }

        if ($failures !== []) {
            throw new ListenerFailures($envelope->name, $failures);
        }

        return null;
    }
}
