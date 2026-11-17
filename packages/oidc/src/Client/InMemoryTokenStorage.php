<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Override;

/**
 * Token storage living in the memory of the process.
 */
final class InMemoryTokenStorage implements TokenStorage
{
    public function __construct(
        private ?TokenSet $tokens = null,
    ) {}

    #[Override]
    public function load(): ?TokenSet
    {
        return $this->tokens;
    }

    #[Override]
    public function save(TokenSet $tokens): void
    {
        $this->tokens = $tokens;
    }

    #[Override]
    public function clear(): void
    {
        $this->tokens = null;
    }
}
