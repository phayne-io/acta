<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Jose;

use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Jose\KeyFactory;
use Acta\Oidc\Jose\WebTokenJose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebTokenJoseTest extends TestCase
{
    #[DataProvider('algorithms')]
    public function testATokenSignedWithAPrivateKeyVerifiesWithItsPublicKeyOnly(string $algorithm): void
    {
        $jose = new WebTokenJose();
        $key = KeyFactory::generate($algorithm, ['kid' => 'k']);
        $token = $jose->sign(['sub' => 'ada', 'url' => 'https://example.test/a'], ['typ' => 'JWT', 'kid' => 'k'], $key, $algorithm);
        [$header, $payload] = array_map(
            static fn(string $part): mixed => json_decode((string) base64_decode(strtr($part, '-_', '+/')), true),
            array_slice(explode('.', $token), 0, 2),
        );

        self::assertSame(['typ' => 'JWT', 'kid' => 'k', 'alg' => $algorithm], $header);
        self::assertSame(['sub' => 'ada', 'url' => 'https://example.test/a'], $payload);
        self::assertTrue($jose->supports($algorithm));
        self::assertTrue($jose->verify($token, KeyFactory::toPublic($key), $algorithm));
        self::assertFalse($jose->verify($token, KeyFactory::toPublic(KeyFactory::generate($algorithm)), $algorithm));
        self::assertFalse($jose->verify(substr($token, 0, -4) . 'AAAA', KeyFactory::toPublic($key), $algorithm));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function algorithms(): iterable
    {
        foreach (['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512', 'ES256', 'ES384', 'ES512', 'EdDSA'] as $algorithm) {
            yield $algorithm => [$algorithm];
        }
    }

    public function testTheAlgorithmIsImposedByTheCaller(): void
    {
        $jose = new WebTokenJose();
        $key = KeyFactory::generate('RS256');
        $token = $jose->sign(['sub' => 'ada'], [], $key, 'RS256');
        $public = array_diff_key(KeyFactory::toPublic($key), ['alg' => true]);

        // Signed with RS256: asking for another algorithm, even one the key could serve, is a failure.
        self::assertTrue($jose->verify($token, $public, 'RS256'));
        self::assertFalse($jose->verify($token, $public, 'PS256'));
        self::assertFalse($jose->verify($token, $public, 'RS512'));
    }

    public function testSharedSecretAlgorithmsAndNoneDoNotExist(): void
    {
        $jose = new WebTokenJose();

        foreach (['none', 'HS256', 'HS512', 'RS1', ''] as $algorithm) {
            self::assertFalse($jose->supports($algorithm));
            self::assertFalse($jose->verify('a.b.c', ['kty' => 'oct', 'k' => 'c2VjcmV0'], $algorithm));
        }

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('"HS256" is not available');

        $jose->sign(['sub' => 'ada'], [], ['kty' => 'oct', 'k' => 'c2VjcmV0'], 'HS256');
    }

    public function testUnusableInputIsASignatureThatDoesNotVerify(): void
    {
        $jose = new WebTokenJose();
        $key = KeyFactory::generate('ES256');
        $token = $jose->sign(['sub' => 'ada'], [], $key, 'ES256');

        self::assertFalse($jose->verify('not a token', KeyFactory::toPublic($key), 'ES256'));
        self::assertFalse($jose->verify($token, ['kty' => 'EC'], 'ES256'));
        self::assertFalse($jose->verify($token, [], 'ES256'));
        self::assertFalse($jose->verify($token, KeyFactory::toPublic(KeyFactory::generate('RS256')), 'ES256'));
    }

    public function testAKeyThatDoesNotFitTheAlgorithmCannotSign(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('The token cannot be signed with RS256');

        new WebTokenJose()->sign(['sub' => 'ada'], [], KeyFactory::generate('ES256'), 'RS256');
    }
}
