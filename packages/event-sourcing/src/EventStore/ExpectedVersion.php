<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Exception\InvalidArgument;

/**
 * The state a stream must be in for an append to succeed.
 *
 * It is the optimistic concurrency check of the event store: the writer states what it
 * believes the stream looks like, and the store refuses the write when it is wrong.
 */
final readonly class ExpectedVersion
{
    private const int ANY = -2;
    private const int STREAM_EXISTS = -1;
    private const int NO_STREAM = 0;

    private function __construct(
        private int $value,
    ) {}

    /**
     * No check: the events are appended whatever the state of the stream.
     */
    public static function any(): self
    {
        return new self(self::ANY);
    }

    /**
     * The stream must not exist yet. Use it when creating an aggregate.
     */
    public static function noStream(): self
    {
        return new self(self::NO_STREAM);
    }

    /**
     * The stream must hold at least one event.
     */
    public static function streamExists(): self
    {
        return new self(self::STREAM_EXISTS);
    }

    /**
     * The last event of the stream must have exactly this version.
     *
     * @throws InvalidArgument when the version is negative
     */
    public static function exactly(int $version): self
    {
        if ($version < 0) {
            throw new InvalidArgument(sprintf('An expected stream version cannot be negative, got %d.', $version));
        }

        return new self($version);
    }

    /**
     * Tells whether a stream currently at `$currentVersion` (0 when it does not exist)
     * satisfies this expectation.
     */
    public function isSatisfiedBy(int $currentVersion): bool
    {
        return match ($this->value) {
            self::ANY => true,
            self::STREAM_EXISTS => $currentVersion > 0,
            default => $currentVersion === $this->value,
        };
    }

    /**
     * Human-readable form, used in error messages.
     */
    public function describe(): string
    {
        return match ($this->value) {
            self::ANY => 'any version',
            self::STREAM_EXISTS => 'an existing stream',
            self::NO_STREAM => 'no stream',
            default => 'version ' . $this->value,
        };
    }
}
