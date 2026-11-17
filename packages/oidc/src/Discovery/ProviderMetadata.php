<?php

declare(strict_types=1);

namespace Acta\Oidc\Discovery;

use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\ProviderUnreachable;
use Override;

/**
 * Description of an identity provider: OpenID Provider Metadata, or OAuth 2.0
 * Authorization Server Metadata (RFC 8414).
 */
final readonly class ProviderMetadata implements MetadataSource
{
    /**
     * @param string               $issuer identifier of the provider, the exact `iss` of its tokens
     * @param array<string, mixed> $values every other member of the metadata document
     */
    public function __construct(
        public string $issuer,
        public ?string $jwksUri = null,
        public ?string $authorizationEndpoint = null,
        public ?string $tokenEndpoint = null,
        public ?string $userinfoEndpoint = null,
        public ?string $deviceAuthorizationEndpoint = null,
        public ?string $endSessionEndpoint = null,
        public array $values = [],
    ) {}

    /**
     * Builds the metadata from a decoded discovery document.
     *
     * @param array<string, mixed> $document
     *
     * @throws ProviderUnreachable when the document has no issuer
     */
    public static function fromArray(array $document): self
    {
        $string = static fn(string $name): ?string => is_string($document[$name] ?? null) && $document[$name] !== ''
            ? $document[$name]
            : null;

        return new self(
            $string('issuer') ?? throw new ProviderUnreachable('The provider metadata has no "issuer".'),
            $string('jwks_uri'),
            $string('authorization_endpoint'),
            $string('token_endpoint'),
            $string('userinfo_endpoint'),
            $string('device_authorization_endpoint'),
            $string('end_session_endpoint'),
            $document,
        );
    }

    #[Override]
    public function metadata(): self
    {
        return $this;
    }

    /**
     * Returns an endpoint, or fails when the provider does not have it.
     *
     * @param 'jwksUri'|'authorizationEndpoint'|'tokenEndpoint'|'userinfoEndpoint'|'deviceAuthorizationEndpoint'|'endSessionEndpoint' $name
     *
     * @throws ConfigurationError
     */
    public function endpoint(string $name): string
    {
        return $this->{$name} ?? throw new ConfigurationError(sprintf(
            'The provider %s does not advertise its %s.',
            $this->issuer,
            strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name)),
        ));
    }
}
