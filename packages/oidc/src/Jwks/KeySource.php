<?php

declare(strict_types=1);

namespace Acta\Oidc\Jwks;

use Acta\Oidc\Exception\ProviderUnreachable;

/**
 * Gives the public keys tokens are verified with.
 */
interface KeySource
{
    /**
     * Returns the keys currently known.
     *
     * @throws ProviderUnreachable when the keys have to be fetched and cannot be
     */
    public function keys(): KeySet;

    /**
     * Fetches the keys again, because a token names a key that is not known.
     *
     * @return KeySet|null the fresh keys, or null when refreshing is not possible or not allowed right now
     *
     * @throws ProviderUnreachable when the keys cannot be fetched
     */
    public function refresh(): ?KeySet;
}
