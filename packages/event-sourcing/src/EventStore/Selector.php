<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Message\Metadata;
use NoDiscard;

/**
 * Describes which events of the global log a reader is interested in.
 *
 * Prooph needed the `standard-projections` package to materialize an `$all` stream, one
 * stream per category and one per message name before they could be read. Because the
 * store keeps a single ordered log, the same sources are plain selectors here:
 *
 * ```php
 * Selector::all();
 * Selector::category('account', 'customer');
 * Selector::stream('account-42');
 * Selector::category('account')->named('banking.account-opened')->matching($matcher);
 * ```
 */
final readonly class Selector
{
    /**
     * @param list<string> $streams      stream names to read, empty for no restriction
     * @param list<string> $categories   stream categories to read, empty for no restriction
     * @param list<string> $messageNames public event names to keep, empty for all
     */
    private function __construct(
        public array $streams = [],
        public array $categories = [],
        public array $messageNames = [],
        public ?MetadataMatcher $matcher = null,
    ) {}

    /**
     * Every event of the store.
     */
    public static function all(): self
    {
        return new self();
    }

    /**
     * The events of the given streams.
     */
    public static function stream(StreamName|string ...$streams): self
    {
        return new self(streams: array_map(strval(...), array_values($streams)));
    }

    /**
     * The events of every stream of the given categories.
     */
    public static function category(string ...$categories): self
    {
        return new self(categories: array_values($categories));
    }

    /**
     * Returns a selector that also reads the given streams.
     */
    #[NoDiscard]
    public function orStream(StreamName|string ...$streams): self
    {
        return clone($this, ['streams' => [...$this->streams, ...array_map(strval(...), array_values($streams))]]);
    }

    /**
     * Returns a selector that also reads the given categories.
     */
    #[NoDiscard]
    public function orCategory(string ...$categories): self
    {
        return clone($this, ['categories' => [...$this->categories, ...array_values($categories)]]);
    }

    /**
     * Returns a selector restricted to events with the given public names.
     */
    #[NoDiscard]
    public function named(string ...$messageNames): self
    {
        return clone($this, ['messageNames' => array_values($messageNames)]);
    }

    /**
     * Returns a selector restricted to events whose metadata satisfies the matcher.
     */
    #[NoDiscard]
    public function matching(MetadataMatcher $matcher): self
    {
        return clone($this, ['matcher' => $matcher]);
    }

    /**
     * Tells whether an event is part of the selection.
     */
    public function selects(RecordedEvent $event): bool
    {
        return $this->accepts($event->stream, $event->name, $event->metadata);
    }

    /**
     * Tells whether an event of the given stream, name and metadata is part of the
     * selection. Event store adapters use it on events in their stored form.
     */
    public function accepts(StreamName $stream, string $messageName, Metadata $metadata): bool
    {
        $anySource = $this->streams === [] && $this->categories === [];

        return ($anySource
                || in_array($stream->value, $this->streams, true)
                || in_array($stream->category, $this->categories, true))
            && ($this->messageNames === [] || in_array($messageName, $this->messageNames, true))
            && ($this->matcher === null || $this->matcher->matches($metadata));
    }
}
