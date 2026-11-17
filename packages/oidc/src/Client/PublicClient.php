<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Discovery\ProviderMetadata;
use Override;

/**
 * No authentication, for public clients such as command-line tools and native
 * applications, which cannot keep a secret. Only the identifier is sent; PKCE protects
 * the authorization code.
 */
final readonly class PublicClient implements ClientAuthentication
{
    #[Override]
    public function authenticate(string $clientId, array $form, ProviderMetadata $provider): array
    {
        return [[...$form, 'client_id' => $clientId], []];
    }
}
