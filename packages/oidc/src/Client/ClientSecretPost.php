<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Discovery\ProviderMetadata;
use Override;
use SensitiveParameter;

/**
 * `client_secret_post`: the identifier and the secret among the form parameters.
 */
final readonly class ClientSecretPost implements ClientAuthentication
{
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
    ) {}

    #[Override]
    public function authenticate(string $clientId, array $form, ProviderMetadata $provider): array
    {
        return [[...$form, 'client_id' => $clientId, 'client_secret' => $this->secret], []];
    }
}
