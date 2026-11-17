<?php

declare(strict_types=1);

namespace Acta\Webhook\Signature;

use DateTimeImmutable;

/**
 * What a valid signature vouches for, besides the body.
 */
final readonly class VerifiedSignature
{
    /**
     * @param string|null            $deliveryId identifier of the delivery, the same when the sender retries;
     *                                           null when the scheme carries none
     * @param DateTimeImmutable|null $signedAt   when the sender signed; null when the scheme carries no timestamp
     */
    public function __construct(
        public ?string $deliveryId = null,
        public ?DateTimeImmutable $signedAt = null,
    ) {}
}
