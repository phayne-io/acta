<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Exception\NoToken;
use Acta\Oidc\Exception\ProviderError;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\SystemClock;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Access tokens of a user who logged in earlier, renewed transparently with the
 * refresh token for as long as the provider allows.
 *
 * Save the result of a login in the {@see TokenStorage}; from then on this provider
 * keeps it up to date.
 */
final readonly class StoredTokenProvider implements TokenProvider
{
    /**
     * @param int $margin seconds before its expiry at which a token is renewed
     */
    public function __construct(
        private OAuthClient $client,
        private TokenStorage $storage,
        private int $margin = 30,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @throws NoToken             when nobody logged in, or the session ended and a new login is needed
     * @throws ProviderError       when the provider refuses the refresh for another reason
     * @throws ProviderUnreachable when the provider cannot be reached; the stored tokens are kept
     */
    #[Override]
    public function accessToken(): string
    {
        $tokens = $this->storage->load() ?? throw new NoToken('No token is stored: log in first.');

        return $tokens->expiresWithin($this->margin, $this->clock->now())
            ? $this->renew($tokens)->accessToken
            : $tokens->accessToken;
    }

    #[Override]
    public function invalidate(): void
    {
        $tokens = $this->storage->load();

        if ($tokens === null) {
            return;
        }

        try {
            $this->renew($tokens);
        } catch (NoToken) {
            // The storage was cleared: the next call asks for a new login.
        }
    }

    private function renew(TokenSet $tokens): TokenSet
    {
        if ($tokens->refreshToken === null) {
            $this->storage->clear();

            throw new NoToken('The access token has expired and cannot be refreshed: log in again.');
        }

        try {
            $fresh = $this->client->refresh($tokens);
        } catch (ProviderError $error) {
            if ($error->error !== 'invalid_grant') {
                throw $error;
            }

            $this->storage->clear();

            throw new NoToken('The session has ended at the provider: log in again.', previous: $error);
        }

        // An ID token is only returned by some providers on refresh: keep the claims of the login.
        $fresh = $fresh->idTokenClaims === null && $tokens->idTokenClaims !== null
            ? $fresh->withIdTokenClaims($tokens->idTokenClaims)
            : $fresh;
        $this->storage->save($fresh);

        return $fresh;
    }
}
