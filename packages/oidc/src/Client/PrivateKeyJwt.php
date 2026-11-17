<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Discovery\ProviderMetadata;
use Acta\Oidc\Jose\KeyFactory;
use Acta\Oidc\Jose\Signer;
use Acta\Oidc\Jose\WebTokenJose;
use Acta\Oidc\SystemClock;
use Override;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * `private_key_jwt`: the client signs a short-lived assertion with its private key
 * (RFC 7523). No secret is shared with the provider, which only knows the public key.
 */
final readonly class PrivateKeyJwt implements ClientAuthentication
{
    private const string ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /**
     * @param array<string, mixed> $jwk              private key, as a JSON Web Key; its `kid` is put in the assertion
     * @param string               $algorithm        signature algorithm, matching the key
     * @param int                  $lifetime         seconds an assertion stays valid
     * @param bool                 $issuerAsAudience addresses the assertion to the issuer rather than to the
     *                                               token endpoint, as the most recent profiles ask
     */
    public function __construct(
        #[SensitiveParameter]
        private array $jwk,
        private string $algorithm = 'RS256',
        private int $lifetime = 60,
        private bool $issuerAsAudience = false,
        private ClockInterface $clock = new SystemClock(),
        private Signer $signer = new WebTokenJose(),
    ) {}

    /**
     * Builds the authentication from a private key in PEM format.
     *
     * @param string|null $keyId    the `kid` the provider knows the public key under
     * @param string|null $password passphrase of an encrypted key
     */
    public static function fromPem(
        #[SensitiveParameter]
        string $pem,
        string $algorithm = 'RS256',
        ?string $keyId = null,
        #[SensitiveParameter]
        ?string $password = null,
    ): self {
        return new self(KeyFactory::fromPem($pem, $password, $keyId === null ? [] : ['kid' => $keyId]), $algorithm);
    }

    #[Override]
    public function authenticate(string $clientId, array $form, ProviderMetadata $provider): array
    {
        $now = $this->clock->now()->getTimestamp();
        $assertion = $this->signer->sign(
            [
                'iss' => $clientId,
                'sub' => $clientId,
                'aud' => $this->issuerAsAudience ? $provider->issuer : $provider->endpoint('tokenEndpoint'),
                'jti' => bin2hex(random_bytes(16)),
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + $this->lifetime,
            ],
            ['typ' => 'JWT', ...(is_string($this->jwk['kid'] ?? null) ? ['kid' => $this->jwk['kid']] : [])],
            $this->jwk,
            $this->algorithm,
        );

        return [
            [...$form, 'client_id' => $clientId, 'client_assertion_type' => self::ASSERTION_TYPE, 'client_assertion' => $assertion],
            [],
        ];
    }
}
