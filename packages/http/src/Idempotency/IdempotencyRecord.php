<?php

declare(strict_types=1);

namespace Acta\Http\Idempotency;

/**
 * What the store knows about an idempotency key.
 */
final readonly class IdempotencyRecord
{
    /**
     * @param string              $fingerprint digest of the request that first used the key
     * @param StoredResponse|null $response    its response, or null while it is still being handled
     */
    public function __construct(
        public string $fingerprint,
        public ?StoredResponse $response,
    ) {}
}
