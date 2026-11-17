<?php

declare(strict_types=1);

namespace Acta\Oidc\Jwks;

use Acta\Oidc\Cache\ArrayCache;
use Acta\Oidc\Discovery\MetadataSource;
use Acta\Oidc\Http\Transport;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches the keys of the provider from its `jwks_uri` and keeps them in the cache.
 *
 * Providers rotate their keys. When a token names a key that is not in the cache, the
 * keys are fetched again, at most once per `$refreshInterval`: the rotation is followed
 * within seconds, while a flood of tokens with invented key identifiers cannot turn
 * this server into a way to hammer the provider.
 */
final readonly class JwksProvider implements KeySource
{
    /**
     * @param int $ttl             seconds the keys are kept in the cache
     * @param int $refreshInterval minimum number of seconds between two fetches triggered by unknown keys
     */
    public function __construct(
        private MetadataSource $provider,
        private Transport $transport,
        private CacheInterface $cache = new ArrayCache(),
        private int $ttl = 3600,
        private int $refreshInterval = 30,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function keys(): KeySet
    {
        $cached = $this->cache->get($this->cacheKey());

        return is_array($cached) ? new KeySet($cached) : $this->fetch();
    }

    #[Override]
    public function refresh(): ?KeySet
    {
        if ($this->cache->has($this->cacheKey() . '.fetched')) {
            $this->logger->info('The keys of {issuer} were fetched less than {interval} s ago: not fetching them again.', [
                'issuer' => $this->provider->metadata()->issuer,
                'interval' => $this->refreshInterval,
            ]);

            return null;
        }

        return $this->fetch();
    }

    private function fetch(): KeySet
    {
        $metadata = $this->provider->metadata();
        $key = $this->cacheKey();

        // Marked before fetching, so that a failing provider is not retried in a loop either.
        $this->cache->set($key . '.fetched', true, $this->refreshInterval);

        $keys = KeySet::fromArray($this->transport->get($metadata->endpoint('jwksUri')));
        $this->cache->set($key, $keys->keys, $this->ttl);
        $this->logger->info('Fetched {count} signature key(s) of {issuer}.', ['count' => count($keys->keys), 'issuer' => $metadata->issuer]);

        return $keys;
    }

    private function cacheKey(): string
    {
        return 'acta.oidc.jwks.' . hash('sha256', $this->provider->metadata()->issuer);
    }
}
