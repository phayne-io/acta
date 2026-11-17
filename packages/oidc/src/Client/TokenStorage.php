<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

/**
 * Keeps the tokens of one user between two uses: a session, a file, a keychain.
 */
interface TokenStorage
{
    public function load(): ?TokenSet;

    public function save(TokenSet $tokens): void;

    public function clear(): void;
}
