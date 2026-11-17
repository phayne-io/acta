<?php

declare(strict_types=1);

namespace Acta\Webhook\Signature;

use Acta\Webhook\Exception\InvalidSignature;
use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;

/**
 * How webhooks are signed: which headers carry what, and what exactly is signed.
 *
 * Senders disagree on this. {@see StandardWebhooks} follows the open specification of
 * the same name; {@see HmacSignature} covers the common "HMAC of the body in a header"
 * family. Implement the interface for anything else.
 */
interface SignatureScheme
{
    /**
     * Checks that a received webhook was signed by the holder of the secret.
     *
     * @param string $body the body exactly as received: signatures cover bytes, not decoded JSON
     *
     * @throws InvalidSignature
     */
    public function verify(RequestInterface $request, string $body): VerifiedSignature;

    /**
     * Returns the headers that sign a webhook about to be sent.
     *
     * @param string $deliveryId identifier of the delivery, the same for every attempt
     *
     * @return array<string, string>
     */
    public function sign(string $deliveryId, DateTimeImmutable $signedAt, string $body): array;
}
