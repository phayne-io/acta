<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

/**
 * Marker for facts that happened in the domain.
 *
 * A domain event is named in the past tense and is immutable. Its identifier, its
 * timestamp and its metadata live in the {@see Envelope} that carries it, not in the
 * event itself, so the event class holds business data only.
 */
interface DomainEvent {}
