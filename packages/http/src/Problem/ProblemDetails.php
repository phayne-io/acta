<?php

declare(strict_types=1);

namespace Acta\Http\Problem;

use JsonSerializable;
use Override;

/**
 * Problem document of RFC 9457, serialized as `application/problem+json`.
 */
final readonly class ProblemDetails implements JsonSerializable
{
    /** Media type of a serialized problem document. */
    public const string MEDIA_TYPE = 'application/problem+json';

    /** Problem type meaning "nothing more than the HTTP status". */
    public const string BLANK = 'about:blank';

    private const array RESERVED = ['type', 'title', 'status', 'detail', 'instance'];

    /**
     * @param int                  $status     HTTP status code of the response
     * @param string               $title      short summary of the problem type, the same for every occurrence
     * @param string|null          $detail     explanation specific to this occurrence
     * @param string               $type       URI identifying the problem type
     * @param string|null          $instance   URI identifying this occurrence
     * @param array<string, mixed> $extensions additional members; they cannot replace the standard ones
     */
    public function __construct(
        public int $status,
        public string $title,
        public ?string $detail = null,
        public string $type = self::BLANK,
        public ?string $instance = null,
        public array $extensions = [],
    ) {}

    /**
     * Returns a copy with one more extension member.
     */
    public function with(string $name, mixed $value): self
    {
        return clone($this, ['extensions' => [...$this->extensions, $name => $value]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $members = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
        ];

        return [
            ...array_filter($members, static fn(mixed $value): bool => $value !== null),
            ...array_diff_key($this->extensions, array_flip(self::RESERVED)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
