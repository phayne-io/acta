<?php

declare(strict_types=1);

namespace Acta\Http\Idempotency;

use DateTimeImmutable;
use Override;

/**
 * Idempotency store living in the memory of the process: for tests and single-process servers.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{IdempotencyRecord, DateTimeImmutable}> */
    private array $records = [];

    #[Override]
    public function acquire(string $key, string $fingerprint, DateTimeImmutable $now, DateTimeImmutable $expiresAt): ?IdempotencyRecord
    {
        [$record, $expiry] = $this->records[$key] ?? [null, null];

        if ($record !== null && $expiry > $now) {
            return $record;
        }

        $this->records[$key] = [new IdempotencyRecord($fingerprint, null), $expiresAt];

        return null;
    }

    #[Override]
    public function complete(string $key, StoredResponse $response): void
    {
        if (isset($this->records[$key])) {
            [$record, $expiry] = $this->records[$key];
            $this->records[$key] = [new IdempotencyRecord($record->fingerprint, $response), $expiry];
        }
    }

    #[Override]
    public function release(string $key): void
    {
        unset($this->records[$key]);
    }
}
