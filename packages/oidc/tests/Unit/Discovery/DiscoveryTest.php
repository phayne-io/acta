<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Discovery;

use Acta\Oidc\Cache\ArrayCache;
use Acta\Oidc\Discovery\Discovery;
use Acta\Oidc\Discovery\ProviderMetadata;
use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Testing\FakeIdentityProvider;
use Acta\Oidc\Tests\OidcTestCase;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class DiscoveryTest extends OidcTestCase
{
    public function testTheMetadataIsReadFromTheDiscoveryDocument(): void
    {
        $metadata = $this->discovery->metadata();

        self::assertSame(self::ISSUER, $metadata->issuer);
        self::assertSame(self::ISSUER . '/jwks', $metadata->jwksUri);
        self::assertSame(self::ISSUER . '/authorize', $metadata->authorizationEndpoint);
        self::assertSame(self::ISSUER . '/token', $metadata->tokenEndpoint);
        self::assertSame(self::ISSUER . '/userinfo', $metadata->userinfoEndpoint);
        self::assertSame(self::ISSUER . '/device', $metadata->deviceAuthorizationEndpoint);
        self::assertSame(self::ISSUER . '/logout', $metadata->endSessionEndpoint);
        self::assertSame(['S256'], $metadata->values['code_challenge_methods_supported']);
        self::assertSame($metadata, $metadata->metadata());
    }

    public function testTheDocumentIsCachedAcrossInstancesUntilItExpires(): void
    {
        $this->discovery->metadata();
        $this->discovery->metadata();
        new Discovery(self::ISSUER, $this->transport, $this->cache)->metadata();

        self::assertSame(1, $this->idp->requestCount('/.well-known/openid-configuration'));

        $this->clock->advance('+1 hour');
        new Discovery(self::ISSUER, $this->transport, $this->cache)->metadata();

        self::assertSame(2, $this->idp->requestCount('/.well-known/openid-configuration'));
    }

    public function testItFallsBackToTheOAuthMetadataLocation(): void
    {
        $issuer = 'https://idp.example.test/realms/acme';
        $idp = new FakeIdentityProvider(new ResponseFactory(), new StreamFactory(), $issuer);
        $idp->openIdDiscovery = false;
        $discovery = new Discovery($issuer, new Transport($idp, new RequestFactory(), new StreamFactory()));

        self::assertSame($issuer . '/token', $discovery->metadata()->tokenEndpoint);
        self::assertSame(
            ['https://idp.example.test/realms/acme/.well-known/openid-configuration', 'https://idp.example.test/.well-known/oauth-authorization-server/realms/acme'],
            array_map(static fn($request): string => (string) $request->getUri(), $idp->requests),
        );
    }

    public function testADocumentDescribingAnotherIssuerIsRefused(): void
    {
        // The provider at this address says it is https://idp.example.test, not .../other.
        $idp = new FakeIdentityProvider(new ResponseFactory(), new StreamFactory(), self::ISSUER);
        $transport = new Transport(new class ($idp) implements ClientInterface {
            public function __construct(private readonly FakeIdentityProvider $idp) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->idp->sendRequest($request->withUri($request->getUri()->withPath('/.well-known/openid-configuration')));
            }
        }, new RequestFactory(), new StreamFactory());

        $this->expectException(ProviderUnreachable::class);
        $this->expectExceptionMessage('describes another issuer: https://idp.example.test.');

        new Discovery(self::ISSUER . '/other', $transport)->metadata();
    }

    public function testAnOutageIsReportedWithBothAttempts(): void
    {
        $this->idp->available = false;

        try {
            $this->discovery->metadata();
            self::fail('Discovery should fail.');
        } catch (ProviderUnreachable $exception) {
            self::assertStringStartsWith('The metadata of https://idp.example.test cannot be discovered.', $exception->getMessage());
            self::assertSame(2, substr_count($exception->getMessage(), 'Connection refused'));
        }

        // Nothing was cached: the provider is asked again once it is back.
        $this->idp->available = true;

        self::assertSame(self::ISSUER, $this->discovery->metadata()->issuer);
    }

    #[DataProvider('unacceptableIssuers')]
    public function testAnUnacceptableIssuerIsAConfigurationError(string $issuer): void
    {
        $this->expectException(ConfigurationError::class);

        new Discovery($issuer, $this->transport);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unacceptableIssuers(): iterable
    {
        yield 'not a URL' => ['idp'];
        yield 'another scheme' => ['ftp://idp.example.test'];
        yield 'plain http' => ['http://idp.example.test'];
        yield 'query' => ['https://idp.example.test?tenant=a'];
        yield 'fragment' => ['https://idp.example.test#a'];
    }

    public function testPlainHttpIsAcceptedOnLoopbackOrOnRequest(): void
    {
        new Discovery('http://localhost:8080/realms/dev', $this->transport);
        new Discovery('http://127.0.0.1:8080', $this->transport);
        new Discovery('http://keycloak:8080', $this->transport, new ArrayCache(), 3600, allowInsecure: true);

        $this->expectNotToPerformAssertions();
    }

    public function testMetadataBuiltByHandIsItsOwnSource(): void
    {
        $metadata = new ProviderMetadata('https://idp.example.test', tokenEndpoint: 'https://idp.example.test/oauth/token');

        self::assertSame('https://idp.example.test/oauth/token', $metadata->endpoint('tokenEndpoint'));

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('The provider https://idp.example.test does not advertise its device authorization endpoint.');

        $metadata->endpoint('deviceAuthorizationEndpoint');
    }

    public function testADocumentWithoutIssuerIsUnusable(): void
    {
        $this->expectException(ProviderUnreachable::class);
        $this->expectExceptionMessage('has no "issuer"');

        ProviderMetadata::fromArray(['token_endpoint' => 'https://idp.example.test/token']);
    }
}
