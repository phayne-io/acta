<?php

declare(strict_types=1);

namespace Acta\Oidc\Discovery;

use Acta\Oidc\Cache\ArrayCache;
use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\ProviderError;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Http\Transport;
use Override;
use Psr\SimpleCache\CacheInterface;

/**
 * Reads the metadata of an identity provider from its discovery document.
 *
 * The OpenID Connect location is tried first (`/.well-known/openid-configuration`
 * appended to the issuer), then the OAuth 2.0 one of RFC 8414
 * (`/.well-known/oauth-authorization-server` inserted before the path of the issuer).
 * The document is kept in the cache, and must name the very issuer that was asked for:
 * a document describing another issuer is refused.
 */
final class Discovery implements MetadataSource
{
    private ?ProviderMetadata $metadata = null;

    private readonly string $issuer;

    /**
     * @param string $issuer        identifier of the provider, an `https` URL
     * @param int    $ttl           seconds the document is kept in the cache
     * @param bool   $allowInsecure accepts an `http` issuer on any host; loopback hosts are always
     *                              accepted, for local development
     *
     * @throws ConfigurationError when the issuer is not an acceptable URL
     */
    public function __construct(
        string $issuer,
        private readonly Transport $transport,
        private readonly CacheInterface $cache = new ArrayCache(),
        private readonly int $ttl = 3600,
        bool $allowInsecure = false,
    ) {
        $parts = parse_url($issuer);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if ($host === '' || isset($parts['query']) || isset($parts['fragment']) || !in_array($scheme, ['https', 'http'], true)) {
            throw new ConfigurationError(sprintf('"%s" is not an issuer: an https URL without query or fragment is expected.', $issuer));
        }

        if ($scheme === 'http' && !$allowInsecure && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new ConfigurationError(sprintf('The issuer "%s" must use https.', $issuer));
        }

        $this->issuer = $issuer;
    }

    #[Override]
    public function metadata(): ProviderMetadata
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }

        $key = 'acta.oidc.metadata.' . hash('sha256', $this->issuer);
        $cached = $this->cache->get($key);

        if (is_array($cached) && ($cached['issuer'] ?? null) === $this->issuer) {
            return $this->metadata = ProviderMetadata::fromArray(self::map($cached));
        }

        $document = $this->fetch();

        if (($document['issuer'] ?? null) !== $this->issuer) {
            throw new ProviderUnreachable(sprintf(
                'The discovery document of %s describes another issuer: %s.',
                $this->issuer,
                is_string($document['issuer'] ?? null) ? $document['issuer'] : 'none',
            ));
        }

        $this->cache->set($key, $document, $this->ttl);

        return $this->metadata = ProviderMetadata::fromArray($document);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $issuer = rtrim($this->issuer, '/');
        $path = (string) parse_url($issuer, PHP_URL_PATH);
        $origin = $path === '' ? $issuer : substr($issuer, 0, -strlen($path));
        $failures = [];

        foreach ([$issuer . '/.well-known/openid-configuration', $origin . '/.well-known/oauth-authorization-server' . $path] as $url) {
            try {
                return $this->transport->get($url);
            } catch (ProviderUnreachable|ProviderError $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        throw new ProviderUnreachable(sprintf('The metadata of %s cannot be discovered. %s', $this->issuer, implode(' ', $failures)));
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
