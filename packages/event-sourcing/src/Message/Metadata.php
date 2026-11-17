<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

use Countable;
use NoDiscard;
use Override;

/**
 * Immutable bag of technical information attached to a message.
 *
 * Metadata never carries business data: it holds what the infrastructure needs to trace
 * and route a message (correlation, causation, actor, tenant...). Values must be
 * JSON-serializable because metadata is stored next to the event payload.
 */
final readonly class Metadata implements Countable
{
    /** Identifier shared by every message of one business conversation. */
    public const string CORRELATION_ID = 'correlation_id';

    /** Identifier of the message whose handling produced this one. */
    public const string CAUSATION_ID = 'causation_id';

    /** Name of the message whose handling produced this one. */
    public const string CAUSATION_NAME = 'causation_name';

    /** Set to `true` once a message has been handed over to a message producer. */
    public const string HANDLED_ASYNC = 'handled_async';

    /** Origin of an event copied to another stream by a projection. */
    public const string LINK = 'link';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public array $values = [],
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Returns a copy holding `$value` under `$key`.
     */
    #[NoDiscard]
    public function with(string $key, mixed $value): self
    {
        return clone($this, ['values' => [...$this->values, $key => $value]]);
    }

    /**
     * Returns a copy without `$key`.
     */
    #[NoDiscard]
    public function without(string $key): self
    {
        return clone($this, ['values' => array_diff_key($this->values, [$key => true])]);
    }

    /**
     * Returns a copy completed with `$other`, whose values win on conflict.
     *
     * @param self|array<string, mixed> $other
     */
    #[NoDiscard]
    public function merge(self|array $other): self
    {
        $values = $other instanceof self ? $other->values : $other;

        return clone($this, ['values' => [...$this->values, ...$values]]);
    }

    #[Override]
    public function count(): int
    {
        return count($this->values);
    }
}
