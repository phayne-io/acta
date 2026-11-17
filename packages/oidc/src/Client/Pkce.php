<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

/**
 * Proof Key for Code Exchange (RFC 7636), with the S256 method.
 *
 * @internal
 */
final class Pkce
{
    /**
     * Returns a new code verifier: 43 characters carrying 256 bits of entropy.
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
