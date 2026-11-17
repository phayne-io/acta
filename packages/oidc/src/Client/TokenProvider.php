<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Exception\OidcException;

/**
 * Gives a valid access token whenever one is needed, hiding how it is obtained and renewed.
 */
interface TokenProvider
{
    /**
     * Returns an access token that is still valid for a while.
     *
     * @throws OidcException when no token can be obtained
     */
    public function accessToken(): string;

    /**
     * Forgets the current access token, because a service refused it: the next call
     * obtains a new one.
     */
    public function invalidate(): void;
}
