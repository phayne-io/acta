<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Discovery\ProviderMetadata;

/**
 * How a client proves its identity to the token endpoint.
 */
interface ClientAuthentication
{
    /**
     * Adds the credentials of the client to a request to the provider.
     *
     * @param array<string, string> $form parameters of the request
     *
     * @return array{array<string, string>, array<string, string>} the parameters and the headers to send
     */
    public function authenticate(string $clientId, array $form, ProviderMetadata $provider): array;
}
