<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Exception\InvalidArgument;
use Override;
use Stringable;

/**
 * Place of an event in the global, commit-ordered log of the event store.
 *
 * Subscribers and projections keep the position of the last event they handled and ask
 * the store for what comes after it. A position is made of two numbers because a single
 * auto-incremented sequence is not enough under concurrent writes: a slow transaction
 * can commit a low sequence number after a reader has already moved past it. Ordering by
 * (transaction, sequence) and reading only settled transactions removes that gap.
 *
 * Stores that write sequentially, such as the in-memory one, leave the transaction at 0.
 * Treat positions as opaque: compare them, store them, do not compute with them.
 */
final readonly class Position implements Stringable
{
    /**
     * @param int $transaction number of the transaction that wrote the event, 0 when not applicable
     * @param int $sequence    sequence number of the event in the store
     */
    public function __construct(
        public int $transaction = 0,
        public int $sequence = 0,
    ) {
        if ($transaction < 0 || $sequence < 0) {
            throw new InvalidArgument('A position cannot hold negative numbers.');
        }
    }

    /**
     * The position before the first event of the store.
     */
    public static function start(): self
    {
        return new self();
    }

    /**
     * Parses the `<transaction>:<sequence>` form produced by {@see __toString()}.
     *
     * @throws InvalidArgument
     */
    public static function fromString(string $position): self
    {
        if (preg_match('/\A(\d+):(\d+)\z/', $position, $matches) !== 1) {
            throw new InvalidArgument(sprintf('"%s" is not a valid position.', $position));
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    /**
     * Returns a negative, zero or positive integer when this position is before, equal
     * to or after `$other`.
     */
    public function compare(self $other): int
    {
        return [$this->transaction, $this->sequence] <=> [$other->transaction, $other->sequence];
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->transaction . ':' . $this->sequence;
    }
}
