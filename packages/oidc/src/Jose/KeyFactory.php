<?php

declare(strict_types=1);

namespace Acta\Oidc\Jose;

use Acta\Oidc\Exception\ConfigurationError;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Throwable;

/**
 * Builds JSON Web Keys, represented as arrays throughout the package.
 */
final class KeyFactory
{
    /**
     * Reads a private or public key in PEM format.
     *
     * @param string|null          $password   passphrase of an encrypted private key
     * @param array<string, mixed> $attributes members to add to the key, such as `kid`
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationError when the key cannot be read
     */
    public static function fromPem(string $pem, ?string $password = null, array $attributes = []): array
    {
        try {
            return self::export(JWKFactory::createFromKey($pem, $password, $attributes));
        } catch (Throwable $exception) {
            throw new ConfigurationError('The PEM key cannot be read: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Generates a private key suited to a signature algorithm: RSA 2048 for `RS*` and
     * `PS*`, the matching curve for `ES*`, Ed25519 for `EdDSA`.
     *
     * @param array<string, mixed> $attributes members to add to the key, such as `kid`
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationError when the algorithm is unknown
     */
    public static function generate(string $algorithm, array $attributes = []): array
    {
        $attributes = ['use' => 'sig', 'alg' => $algorithm, ...$attributes];

        return self::export(match ($algorithm) {
            'RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512' => JWKFactory::createRSAKey(2048, $attributes),
            'ES256' => JWKFactory::createECKey('P-256', $attributes),
            'ES384' => JWKFactory::createECKey('P-384', $attributes),
            'ES512' => JWKFactory::createECKey('P-521', $attributes),
            'EdDSA' => JWKFactory::createOKPKey('Ed25519', $attributes),
            default => throw new ConfigurationError(sprintf('No key can be generated for the algorithm "%s".', $algorithm)),
        });
    }

    /**
     * Returns the public part of a key, safe to publish in a JWKS.
     *
     * @param array<string, mixed> $jwk
     *
     * @return array<string, mixed>
     */
    public static function toPublic(array $jwk): array
    {
        return self::export(new JWK($jwk)->toPublic());
    }

    /**
     * @return array<string, mixed>
     */
    private static function export(JWK $jwk): array
    {
        $members = [];

        foreach ($jwk->all() as $name => $value) {
            $members[(string) $name] = $value;
        }

        return $members;
    }
}
