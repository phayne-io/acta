<?php

declare(strict_types=1);

namespace Acta\Http\Idempotency;

/**
 * A response kept to be replayed.
 */
final readonly class StoredResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
