<?php

declare(strict_types=1);

namespace Acta\Oidc\Jose;

/**
 * Signs JSON Web Tokens, for clients that authenticate with `private_key_jwt`.
 */
interface Signer
{
    /**
     * Returns a signed token in compact serialization.
     *
     * @param array<string, mixed> $claims    payload of the token
     * @param array<string, mixed> $header    protected header, besides `alg`
     * @param array<string, mixed> $jwk       private key, as a JSON Web Key
     * @param string               $algorithm signature algorithm, such as `RS256`
     */
    public function sign(array $claims, array $header, array $jwk, string $algorithm): string;
}
