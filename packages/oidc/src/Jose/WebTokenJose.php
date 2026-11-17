<?php

declare(strict_types=1);

namespace Acta\Oidc\Jose;

use Acta\Oidc\Exception\ConfigurationError;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\EdDSA;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\Algorithm\SignatureAlgorithm;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Override;
use Throwable;

/**
 * Signatures by `web-token/jwt-library`, the one place this package delegates to it.
 *
 * Only asymmetric algorithms are offered: a resource server never shares a secret with
 * the issuer, and `none` does not exist here.
 */
final class WebTokenJose implements SignatureVerifier, Signer
{
    /** @var array<string, class-string<SignatureAlgorithm>> */
    private const array ALGORITHMS = [
        'RS256' => RS256::class,
        'RS384' => RS384::class,
        'RS512' => RS512::class,
        'PS256' => PS256::class,
        'PS384' => PS384::class,
        'PS512' => PS512::class,
        'ES256' => ES256::class,
        'ES384' => ES384::class,
        'ES512' => ES512::class,
        'EdDSA' => EdDSA::class,
    ];

    /** @var array<string, AlgorithmManager> */
    private array $managers = [];

    #[Override]
    public function supports(string $algorithm): bool
    {
        return isset(self::ALGORITHMS[$algorithm]) && ($algorithm !== 'EdDSA' || extension_loaded('sodium'));
    }

    #[Override]
    public function verify(string $jws, array $jwk, string $algorithm): bool
    {
        if (!$this->supports($algorithm)) {
            return false;
        }

        try {
            $parsed = new CompactSerializer()->unserialize($jws);

            if (($parsed->getSignature(0)->getProtectedHeader()['alg'] ?? null) !== $algorithm) {
                return false;
            }

            return new JWSVerifier($this->manager($algorithm))->verifyWithKey($parsed, new JWK($jwk), 0);
        } catch (Throwable) {
            // An unusable key or an unreadable token is a signature that does not verify.
            return false;
        }
    }

    #[Override]
    public function sign(array $claims, array $header, array $jwk, string $algorithm): string
    {
        if (!$this->supports($algorithm)) {
            throw new ConfigurationError(sprintf('The signature algorithm "%s" is not available.', $algorithm));
        }

        try {
            $jws = new JWSBuilder($this->manager($algorithm))
                ->create()
                ->withPayload(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
                ->addSignature(new JWK($jwk), [...$header, 'alg' => $algorithm])
                ->build();

            return new CompactSerializer()->serialize($jws, 0);
        } catch (Throwable $exception) {
            throw new ConfigurationError(
                sprintf('The token cannot be signed with %s: %s', $algorithm, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    private function manager(string $algorithm): AlgorithmManager
    {
        return $this->managers[$algorithm] ??= new AlgorithmManager([new (self::ALGORITHMS[$algorithm])()]);
    }
}
