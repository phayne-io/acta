<?php

declare(strict_types=1);

namespace Acta\Oidc\Jwks;

use Override;

/**
 * A set of public JSON Web Keys.
 *
 * A key set built by hand is its own {@see KeySource}, for issuers whose keys are
 * distributed out of band.
 */
final readonly class KeySet implements KeySource
{
    /** @var list<array<string, mixed>> */
    public array $keys;

    /**
     * @param array<mixed> $keys JSON Web Keys; entries that are not signature keys are left out
     */
    public function __construct(array $keys)
    {
        $signing = [];

        foreach ($keys as $key) {
            if (!is_array($key) || !is_string($key['kty'] ?? null) || !in_array($key['use'] ?? 'sig', ['sig', null], true)) {
                continue;
            }

            $members = [];

            foreach ($key as $name => $value) {
                $members[(string) $name] = $value;
            }

            $signing[] = $members;
        }

        $this->keys = $signing;
    }

    /**
     * Builds a key set from a decoded JWKS document.
     *
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        return new self(is_array($document['keys'] ?? null) ? $document['keys'] : []);
    }

    /**
     * Returns the keys that may have produced a signature.
     *
     * @param string|null $keyId     the `kid` of the token; without it, every key of the right type is a candidate
     * @param string      $algorithm the algorithm of the signature
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(?string $keyId, string $algorithm): array
    {
        $type = match (true) {
            str_starts_with($algorithm, 'RS'), str_starts_with($algorithm, 'PS') => 'RSA',
            str_starts_with($algorithm, 'ES') => 'EC',
            $algorithm === 'EdDSA' => 'OKP',
            default => null,
        };

        return array_values(array_filter(
            $this->keys,
            static fn(array $key): bool => $key['kty'] === $type
                && ($keyId === null || ($key['kid'] ?? null) === $keyId)
                && (!isset($key['alg']) || $key['alg'] === $algorithm),
        ));
    }

    #[Override]
    public function keys(): self
    {
        return $this;
    }

    #[Override]
    public function refresh(): null
    {
        return null;
    }
}
