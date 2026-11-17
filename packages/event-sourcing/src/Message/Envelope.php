<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

use Acta\EventSourcing\Uuid;
use DateTimeImmutable;
use NoDiscard;

/**
 * A message together with everything the infrastructure knows about it.
 *
 * Prooph stored the identifier, the creation date and the metadata inside the message,
 * which forced every message to extend `DomainMessage`. Here the message is a plain
 * object and the envelope carries the rest, so domain classes stay free of
 * infrastructure concerns.
 */
final readonly class Envelope
{
    /**
     * @param object            $message   the command, query or event
     * @param Uuid              $id        unique identifier of this message
     * @param string            $name      public name, see {@see MessageName}
     * @param DateTimeImmutable $createdAt instant the message was created
     * @param Metadata          $metadata  technical context of the message
     */
    public function __construct(
        public object $message,
        public Uuid $id,
        public string $name,
        public DateTimeImmutable $createdAt,
        public Metadata $metadata = new Metadata(),
    ) {}

    public function kind(): MessageKind
    {
        return MessageKind::of($this->message);
    }

    /**
     * Returns a copy whose metadata is replaced by `$metadata`.
     */
    #[NoDiscard]
    public function withMetadata(Metadata $metadata): self
    {
        return clone($this, ['metadata' => $metadata]);
    }

    /**
     * Returns a copy with one more metadata entry.
     */
    #[NoDiscard]
    public function withAddedMetadata(string $key, mixed $value): self
    {
        return clone($this, ['metadata' => $this->metadata->with($key, $value)]);
    }
}
