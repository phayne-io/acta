<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Exception\InvalidArgument;
use Override;
use Stringable;

/**
 * Name of an event stream.
 *
 * Streams follow the `<category>-<id>` convention: everything before the first dash is
 * the category, usually the aggregate type, and the rest identifies one instance. The
 * category is what {@see Selector::category()} reads, which replaces the category
 * projections Prooph needed. A name without a dash is its own category.
 */
final readonly class StreamName implements Stringable
{
    private const int MAX_LENGTH = 255;

    /** Part of the name before the first dash. */
    public string $category;

    /** Part of the name after the first dash, `null` when there is none. */
    public ?string $id;

    private function __construct(
        public string $value,
    ) {
        $parts = explode('-', $value, 2);

        $this->category = $parts[0];
        $this->id = $parts[1] ?? null;
    }

    /**
     * @throws InvalidArgument when the name is empty, too long or contains whitespace
     */
    public static function fromString(string $name): self
    {
        if ($name === '' || strlen($name) > self::MAX_LENGTH || preg_match('/\s/', $name) === 1) {
            throw new InvalidArgument(sprintf(
                'A stream name must be 1 to %d characters long without whitespace, got "%s".',
                self::MAX_LENGTH,
                $name,
            ));
        }

        return new self($name);
    }

    /**
     * Builds the name of the stream of one aggregate instance.
     *
     * @throws InvalidArgument when the category is empty or contains a dash
     */
    public static function for(string $category, string|Stringable $id): self
    {
        if ($category === '' || str_contains($category, '-')) {
            throw new InvalidArgument(sprintf(
                'A stream category must be a non-empty string without dash, got "%s".',
                $category,
            ));
        }

        return self::fromString($category . '-' . $id);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
