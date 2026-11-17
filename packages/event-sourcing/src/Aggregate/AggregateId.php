<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Aggregate;

use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Uuid;
use Override;
use Stringable;

/**
 * Base class for typed aggregate identifiers.
 *
 * Extending it is all it takes to get a dedicated identifier type, which prevents
 * passing the identifier of one aggregate where another is expected:
 *
 * ```php
 * final readonly class AccountId extends AggregateId {}
 *
 * $id = AccountId::generate();
 * $same = AccountId::fromString($id->value);
 * ```
 *
 * Generated identifiers are version 7 UUIDs. Any non-empty string without whitespace is
 * accepted by {@see fromString()}, so natural keys work too.
 */
abstract readonly class AggregateId implements Stringable
{
    final private function __construct(
        public string $value,
    ) {}

    public static function generate(): static
    {
        return new static(Uuid::v7()->value);
    }

    /**
     * @throws InvalidArgument when the value is empty or contains whitespace
     */
    public static function fromString(string $value): static
    {
        if ($value === '' || preg_match('/\s/', $value) === 1) {
            throw new InvalidArgument(sprintf(
                'An aggregate identifier must be a non-empty string without whitespace, got "%s".',
                $value,
            ));
        }

        return new static($value);
    }

    /**
     * Two identifiers are equal when they have the same type and the same value.
     */
    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
