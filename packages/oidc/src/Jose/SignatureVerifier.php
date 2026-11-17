<?php

declare(strict_types=1);

namespace Acta\Oidc\Jose;

/**
 * Checks the signature of a JSON Web Signature. The only cryptographic operation a
 * resource server needs, kept behind a port so that the library doing it can be replaced.
 */
interface SignatureVerifier
{
    /**
     * Tells whether a signature algorithm, such as `RS256`, is available.
     */
    public function supports(string $algorithm): bool;

    /**
     * Tells whether a compact JWS was signed, with the given algorithm, by the holder of
     * the private part of a key.
     *
     * The algorithm is imposed by the caller, never read from the token alone.
     *
     * @param string               $jws       the token, in compact serialization
     * @param array<string, mixed> $jwk       public key, as a JSON Web Key
     * @param string               $algorithm algorithm the signature must have been made with
     */
    public function verify(string $jws, array $jwk, string $algorithm): bool;
}
