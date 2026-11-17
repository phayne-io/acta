<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Jwks;

use Acta\Oidc\Discovery\ProviderMetadata;
use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Jose\KeyFactory;
use Acta\Oidc\Jwks\JwksProvider;
use Acta\Oidc\Jwks\KeySet;
use Acta\Oidc\Tests\Fixture\RecordingLogger;
use Acta\Oidc\Tests\OidcTestCase;

final class JwksTest extends OidcTestCase
{
    public function testTheKeysAreFetchedOnceAndCached(): void
    {
        $keys = $this->jwks->keys();

        self::assertCount(1, $keys->keys);
        self::assertSame('key-1', $keys->keys[0]['kid']);
        self::assertArrayNotHasKey('d', $keys->keys[0]);

        $this->jwks->keys();
        new JwksProvider($this->discovery, $this->transport, $this->cache)->keys();

        self::assertSame(1, $this->idp->requestCount('/jwks'));

        $this->clock->advance('+1 hour');
        $this->jwks->keys();

        self::assertSame(2, $this->idp->requestCount('/jwks'));
    }

    public function testRefreshingIsRateLimited(): void
    {
        $logger = new RecordingLogger();
        $jwks = new JwksProvider($this->discovery, $this->transport, $this->cache, refreshInterval: 60, logger: $logger);

        $jwks->keys();
        $this->idp->rotateKeys();

        self::assertNull($jwks->refresh());

        $this->clock->advance('+59 seconds');

        self::assertNull($jwks->refresh());

        $this->clock->advance('+1 second');

        self::assertSame('key-2', $jwks->refresh()?->keys[0]['kid']);
        self::assertNull($jwks->refresh());
        self::assertSame('key-2', $jwks->keys()->keys[0]['kid']);
        self::assertSame(2, $this->idp->requestCount('/jwks'));
        self::assertSame(['info', 'info', 'info', 'info', 'info'], $logger->levels());
    }

    public function testAProviderWithoutJwksUriCannotBeUsed(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('does not advertise its jwks uri');

        new JwksProvider(new ProviderMetadata(self::ISSUER), $this->transport)->keys();
    }

    public function testOnlySignatureKeysAreKept(): void
    {
        $keys = new KeySet([
            ['kty' => 'RSA', 'kid' => 'sig', 'use' => 'sig', 'n' => 'x', 'e' => 'AQAB'],
            ['kty' => 'RSA', 'kid' => 'enc', 'use' => 'enc', 'n' => 'x', 'e' => 'AQAB'],
            ['kty' => 'EC', 'kid' => 'unspecified', 'crv' => 'P-256', 'x' => 'x', 'y' => 'y'],
            ['kid' => 'no type'],
            'garbage',
        ]);

        self::assertSame(['sig', 'unspecified'], array_column($keys->keys, 'kid'));
        self::assertSame($keys, $keys->keys());
        self::assertNull($keys->refresh());
        self::assertSame([], KeySet::fromArray(['keys' => 'nope'])->keys);
    }

    public function testCandidatesMatchTheIdentifierTheTypeAndTheDeclaredAlgorithm(): void
    {
        $keys = new KeySet([
            ['kty' => 'RSA', 'kid' => 'a', 'alg' => 'RS256'],
            ['kty' => 'RSA', 'kid' => 'b'],
            ['kty' => 'EC', 'kid' => 'c', 'crv' => 'P-256'],
            ['kty' => 'OKP', 'kid' => 'd', 'crv' => 'Ed25519'],
        ]);
        $ids = static fn(?string $kid, string $algorithm): array => array_column($keys->candidates($kid, $algorithm), 'kid');

        self::assertSame(['a'], $ids('a', 'RS256'));
        self::assertSame([], $ids('a', 'PS256'));
        self::assertSame(['b'], $ids('b', 'PS256'));
        self::assertSame(['a', 'b'], $ids(null, 'RS256'));
        self::assertSame(['c'], $ids(null, 'ES256'));
        self::assertSame(['d'], $ids(null, 'EdDSA'));
        self::assertSame([], $ids('c', 'RS256'));
        self::assertSame([], $ids(null, 'HS256'));
    }

    public function testKeysAreReadFromPemAndReducedToTheirPublicPart(): void
    {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($resource);
        openssl_pkey_export($resource, $pem, 'passphrase');
        self::assertIsString($pem);

        $private = KeyFactory::fromPem($pem, 'passphrase', ['kid' => 'client-key']);
        $public = KeyFactory::toPublic($private);

        self::assertSame('EC', $private['kty']);
        self::assertSame('client-key', $private['kid']);
        self::assertArrayHasKey('d', $private);
        self::assertArrayNotHasKey('d', $public);
        self::assertSame($private['x'], $public['x']);

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('The PEM key cannot be read');

        KeyFactory::fromPem('not a key');
    }

    public function testNoKeyIsGeneratedForAnUnknownAlgorithm(): void
    {
        $this->expectException(ConfigurationError::class);

        KeyFactory::generate('HS256');
    }
}
