<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Cache\ArrayCache;
use Acta\Oidc\SystemClock;
use Override;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Access tokens of the client itself, for service-to-service calls.
 *
 * A token is requested once, kept in the cache and renewed shortly before it expires,
 * so that the services called never receive a token about to die in flight.
 */
final class ClientCredentialsTokenProvider implements TokenProvider
{
    /** Token of a provider that does not say when it expires: kept for this instance only. */
    private ?TokenSet $unbounded = null;

    /**
     * @param list<string>          $scopes
     * @param int                   $margin     seconds before its expiry at which a token is renewed
     * @param array<string, string> $parameters additional parameters of the token request, such as `audience`
     */
    public function __construct(
        private readonly OAuthClient $client,
        private readonly array $scopes = [],
        private readonly CacheInterface $cache = new ArrayCache(),
        private readonly int $margin = 30,
        private readonly array $parameters = [],
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    #[Override]
    public function accessToken(): string
    {
        $now = $this->clock->now();
        $cached = $this->cache->get($this->cacheKey());
        $tokens = (is_array($cached) ? TokenSet::fromArray($cached) : null) ?? $this->unbounded;

        if ($tokens !== null && !$tokens->expiresWithin($this->margin, $now)) {
            return $tokens->accessToken;
        }

        $tokens = $this->client->clientCredentials($this->scopes, $this->parameters);

        if ($tokens->expiresAt === null) {
            $this->unbounded = $tokens;
        } else {
            $this->cache->set($this->cacheKey(), $tokens->toArray(), max(1, $tokens->expiresAt->getTimestamp() - $now->getTimestamp()));
        }

        return $tokens->accessToken;
    }

    #[Override]
    public function invalidate(): void
    {
        $this->unbounded = null;
        $this->cache->delete($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'acta.oidc.token.' . hash('sha256', (string) json_encode([
            $this->client->issuer(),
            $this->client->clientId,
            $this->scopes,
            $this->parameters,
        ]));
    }
}
