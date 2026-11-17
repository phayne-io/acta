<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * A device authorization in progress (RFC 8628): what to show the user, and what to
 * poll the provider with.
 */
final readonly class DeviceAuthorization
{
    /**
     * @param string            $deviceCode              secret the client polls with
     * @param string            $userCode                short code the user types on the verification page
     * @param string            $verificationUri         page the user opens on another device
     * @param string|null       $verificationUriComplete same page with the code filled in, to show as a link or a QR code
     * @param DateTimeImmutable $expiresAt               when the codes stop being usable
     * @param int               $interval                seconds to wait between two polls
     */
    public function __construct(
        #[SensitiveParameter]
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public ?string $verificationUriComplete,
        public DateTimeImmutable $expiresAt,
        public int $interval = 5,
    ) {}

    /**
     * Returns a copy polling less often, after the provider asked to slow down.
     */
    public function slowedDown(): self
    {
        return clone($this, ['interval' => $this->interval + 5]);
    }
}
