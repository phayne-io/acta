<?php

declare(strict_types=1);

namespace Acta\Oidc\Discovery;

use Acta\Oidc\Exception\ProviderUnreachable;

/**
 * Gives the description of the identity provider: its issuer and its endpoints.
 *
 * {@see Discovery} reads it from the provider; a {@see ProviderMetadata} built by hand
 * is its own source, for providers without discovery document.
 */
interface MetadataSource
{
    /**
     * @throws ProviderUnreachable when the description has to be fetched and cannot be
     */
    public function metadata(): ProviderMetadata;
}
