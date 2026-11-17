<?php

declare(strict_types=1);

namespace Acta\Http\Idempotency;

use DateTimeImmutable;

/**
 * Remembers which idempotency keys were used and what was answered.
 *
 * Check an implementation with {@see \Acta\Http\Testing\IdempotencyStoreContract}.
 */
interface IdempotencyStore
{
    /**
     * Reserves a key for the request being handled.
     *
     * The reservation must be atomic: of two concurrent calls with the same key, only
     * one gets null. An expired key counts as unused.
     *
     * @param string            $fingerprint digest of the request
     * @param DateTimeImmutable $now         the current time, by which earlier uses of the key are judged expired
     * @param DateTimeImmutable $expiresAt   when the key may be forgotten
     *
     * @return IdempotencyRecord|null null when the key was free and is now reserved;
     *                                otherwise what is known about its earlier use
     */
    public function acquire(string $key, string $fingerprint, DateTimeImmutable $now, DateTimeImmutable $expiresAt): ?IdempotencyRecord;

    /**
     * Stores the response of the request that reserved the key.
     */
    public function complete(string $key, StoredResponse $response): void;

    /**
     * Frees a reserved key whose request failed, so that it can be retried.
     */
    public function release(string $key): void;
}
