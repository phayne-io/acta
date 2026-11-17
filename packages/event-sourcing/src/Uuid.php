<?php

declare(strict_types=1);

namespace Acta\EventSourcing;

use Acta\EventSourcing\Exception\InvalidArgument;
use DateTimeInterface;
use Override;
use Stringable;

/**
 * RFC 9562 UUID value object, with a generator for time-ordered version 7 identifiers.
 *
 * Version 7 identifiers sort by creation time, which keeps B-tree indexes compact and
 * makes message identifiers meaningful when read in a log. The implementation relies on
 * `random_bytes()` only, so no third-party package is needed.
 */
final readonly class Uuid implements Stringable
{
    private const string PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private function __construct(
        /** Canonical lowercase representation, e.g. `018f4e3a-7b2c-7d1e-9a0b-1c2d3e4f5a6b`. */
        public string $value,
    ) {}

    /**
     * Generates a version 7 (Unix time-ordered) UUID.
     *
     * @param DateTimeInterface|null $at instant encoded in the identifier; defaults to the current time
     */
    public static function v7(?DateTimeInterface $at = null): self
    {
        $milliseconds = $at === null
            ? (int) floor(microtime(true) * 1000)
            : (int) $at->format('Uv');

        $hex = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT) . bin2hex(random_bytes(10));
        $hex[12] = '7';
        $hex[16] = dechex(0x8 | (hexdec($hex[16]) & 0x3));

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    /**
     * @throws InvalidArgument when the string is not a canonical UUID
     */
    public static function fromString(string $value): self
    {
        $value = strtolower($value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgument(sprintf('"%s" is not a valid UUID.', $value));
        }

        return new self($value);
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
