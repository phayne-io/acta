<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests;

use Acta\Oidc\Cache\ArrayCache;
use Acta\Oidc\Discovery\Discovery;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Jwks\JwksProvider;
use Acta\Oidc\Testing\FakeIdentityProvider;
use Acta\Oidc\Tests\Fixture\FrozenClock;
use Acta\Oidc\Token\JwtVerifier;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sets up a fake identity provider, a frozen clock and the objects reading from them.
 */
abstract class OidcTestCase extends TestCase
{
    protected const string ISSUER = 'https://idp.example.test';

    protected FrozenClock $clock;

    protected FakeIdentityProvider $idp;

    protected Transport $transport;

    protected ArrayCache $cache;

    protected Discovery $discovery;

    protected JwksProvider $jwks;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->idp = new FakeIdentityProvider(new ResponseFactory(), new StreamFactory(), self::ISSUER, clock: $this->clock);
        $this->transport = new Transport($this->idp, new RequestFactory(), new StreamFactory());
        $this->cache = new ArrayCache($this->clock);
        $this->discovery = new Discovery(self::ISSUER, $this->transport, $this->cache);
        $this->jwks = new JwksProvider($this->discovery, $this->transport, $this->cache);
    }

    /**
     * @param list<string> $algorithms
     */
    protected function jwtVerifier(array $algorithms = ['RS256', 'PS256', 'ES256', 'EdDSA'], int $leeway = 30): JwtVerifier
    {
        return new JwtVerifier($this->discovery, $this->jwks, $algorithms, $leeway, $this->clock);
    }

    /**
     * @param array<non-empty-string, string> $headers
     */
    protected static function serverRequest(array $headers = []): ServerRequestInterface
    {
        return new ServerRequest([], [], 'https://api.example.test/accounts', 'GET', 'php://memory', $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $map = [];

        foreach ($decoded as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
