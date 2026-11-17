<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Discovery\ProviderMetadata;
use Override;
use SensitiveParameter;

/**
 * `client_secret_basic`: the identifier and the secret in an HTTP Basic header.
 * The method every provider supports.
 */
final readonly class ClientSecretBasic implements ClientAuthentication
{
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
    ) {}

    #[Override]
    public function authenticate(string $clientId, array $form, ProviderMetadata $provider): array
    {
        // RFC 6749, section 2.3.1: both values are form-encoded before being joined.
        $credentials = base64_encode(urlencode($clientId) . ':' . urlencode($this->secret));

        return [$form, ['Authorization' => 'Basic ' . $credentials]];
    }
}
