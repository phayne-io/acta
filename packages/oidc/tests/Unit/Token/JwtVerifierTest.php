<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Token;

use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Jose\KeyFactory;
use Acta\Oidc\Jwks\KeySet;
use Acta\Oidc\Tests\OidcTestCase;
use Acta\Oidc\Token\JwtVerifier;
use Acta\Oidc\Token\TokenError;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;

final class JwtVerifierTest extends OidcTestCase
{
    public function testAValidTokenIsAccepted(): void
    {
        $token = $this->idp->issueAccessToken(['sub' => 'ada', 'scope' => 'accounts:read']);

        $jwt = $this->jwtVerifier()->verify($token, ['api']);

        self::assertSame($token, $jwt->token);
        self::assertSame('ES256', $jwt->algorithm());
        self::assertSame('at+jwt', $jwt->type());
        self::assertSame('key-1', $jwt->header['kid']);
        self::assertSame('ada', $jwt->claims->string('sub'));
        self::assertSame(self::ISSUER, $jwt->claims->string('iss'));
    }

    public function testTheMetadataAndTheKeysAreFetchedOnce(): void
    {
        $verifier = $this->jwtVerifier();

        $verifier->verify($this->idp->issueAccessToken());
        $verifier->verify($this->idp->issueAccessToken());

        self::assertSame(1, $this->idp->requestCount('/.well-known/openid-configuration'));
        self::assertSame(1, $this->idp->requestCount('/jwks'));
    }

    /**
     * @param Closure(self): string $token
     */
    #[DataProvider('refusedTokens')]
    public function testATokenIsRefused(Closure $token, TokenError $reason): void
    {
        try {
            $this->jwtVerifier()->verify($token($this), ['api']);
            self::fail('The token should be refused.');
        } catch (InvalidToken $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    /**
     * @return iterable<string, array{Closure(self): string, TokenError}>
     */
    public static function refusedTokens(): iterable
    {
        $encode = static fn(array $object): string => rtrim(strtr(base64_encode((string) json_encode($object)), '+/', '-_'), '=');
        $now = static fn(self $test): int => $test->clock->now()->getTimestamp();

        yield 'not a token' => [static fn(): string => 'nope', TokenError::Malformed];
        yield 'two parts' => [static fn(): string => 'a.b', TokenError::Malformed];
        yield 'no signature' => [static fn(self $t): string => substr($t->idp->issueAccessToken(), 0, (int) strrpos($t->idp->issueAccessToken(), '.') + 1), TokenError::Malformed];
        yield 'header that is not base64url' => [static fn(): string => 'a+b.c.d', TokenError::Malformed];
        yield 'header that is not JSON' => [static fn(): string => 'bm9wZQ.c.d', TokenError::Malformed];
        yield 'header that is a list' => [static fn(): string => $encode(['RS256']) . '.c.d', TokenError::Malformed];
        yield 'oversized' => [static fn(): string => str_repeat('a', 20_000) . '.b.c', TokenError::Malformed];
        yield 'alg none' => [static fn(): string => $encode(['alg' => 'none']) . '.' . $encode(['sub' => 'ada']) . '.x', TokenError::UnsupportedAlgorithm];
        yield 'no alg' => [static fn(): string => $encode(['typ' => 'JWT']) . '.' . $encode(['sub' => 'ada']) . '.x', TokenError::UnsupportedAlgorithm];
        yield 'shared-secret algorithm' => [
            // The classic confusion attack: the public key of the provider used as an HMAC secret.
            static fn(): string => $encode(['alg' => 'HS256', 'kid' => 'key-1']) . '.' . $encode(['sub' => 'ada']) . '.x',
            TokenError::UnsupportedAlgorithm,
        ];
        yield 'algorithm outside the allowed ones' => [
            static fn(self $t): string => $t->idp->sign(['sub' => 'ada'], [], KeyFactory::generate('ES384'), 'ES384'),
            TokenError::UnsupportedAlgorithm,
        ];
        yield 'critical extension' => [static fn(self $t): string => $t->idp->issueAccessToken([], ['crit' => ['exp']]), TokenError::Malformed];
        yield 'unknown key' => [static fn(self $t): string => $t->idp->issueAccessToken([], ['kid' => 'ghost']), TokenError::UnknownKey];
        yield 'forged with another key of the same name' => [
            static fn(self $t): string => $t->idp->sign(
                ['iss' => self::ISSUER, 'sub' => 'ada', 'aud' => 'api', 'exp' => $now($t) + 300],
                [],
                KeyFactory::generate('ES256', ['kid' => 'key-1']),
            ),
            TokenError::BadSignature,
        ];
        yield 'tampered claims' => [
            static function (self $t) use ($encode, $now): string {
                [$header, , $signature] = explode('.', $t->idp->issueAccessToken());

                return $header . '.' . $encode(['iss' => self::ISSUER, 'sub' => 'admin', 'aud' => 'api', 'exp' => $now($t) + 300]) . '.' . $signature;
            },
            TokenError::BadSignature,
        ];
        yield 'another issuer' => [static fn(self $t): string => $t->idp->issueAccessToken(['iss' => 'https://evil.example.test']), TokenError::WrongIssuer];
        yield 'no issuer' => [static fn(self $t): string => $t->idp->issueAccessToken(['iss' => null]), TokenError::WrongIssuer];
        yield 'no expiry' => [static fn(self $t): string => $t->idp->issueAccessToken(['exp' => null]), TokenError::MissingClaim];
        yield 'expiry that is not a number' => [static fn(self $t): string => $t->idp->issueAccessToken(['exp' => 'tomorrow']), TokenError::MissingClaim];
        yield 'expired' => [static fn(self $t): string => $t->idp->issueAccessToken(['exp' => $now($t) - 30]), TokenError::Expired];
        yield 'not valid yet' => [static fn(self $t): string => $t->idp->issueAccessToken(['nbf' => $now($t) + 31]), TokenError::NotYetValid];
        yield 'issued in the future' => [static fn(self $t): string => $t->idp->issueAccessToken(['iat' => $now($t) + 31]), TokenError::NotYetValid];
        yield 'another audience' => [static fn(self $t): string => $t->idp->issueAccessToken(['aud' => 'billing']), TokenError::WrongAudience];
        yield 'no audience' => [static fn(self $t): string => $t->idp->issueAccessToken(['aud' => null]), TokenError::WrongAudience];
    }

    public function testClockDifferencesWithinTheLeewayAreTolerated(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $verifier = $this->jwtVerifier();

        $verifier->verify($this->idp->issueAccessToken(['exp' => $now - 29]));
        $verifier->verify($this->idp->issueAccessToken(['nbf' => $now + 30, 'iat' => $now + 30]));

        $this->expectException(InvalidToken::class);

        $this->jwtVerifier(leeway: 0)->verify($this->idp->issueAccessToken(['exp' => $now]));
    }

    public function testTheAudienceMayBeAListAndIsOnlyCheckedWhenAsked(): void
    {
        $verifier = $this->jwtVerifier();

        $verifier->verify($this->idp->issueAccessToken(['aud' => ['billing', 'api']]), ['api']);
        $verifier->verify($this->idp->issueAccessToken(['aud' => 'api']), ['other', 'api']);
        $verifier->verify($this->idp->issueAccessToken(['aud' => 'anything']));

        self::assertSame(1, $this->idp->requestCount('/jwks'));
    }

    public function testARotatedKeyIsFetchedWhenATokenNamesIt(): void
    {
        $verifier = $this->jwtVerifier();
        $verifier->verify($this->idp->issueAccessToken());

        $this->clock->advance('+10 minutes');
        $this->idp->rotateKeys();
        $jwt = $verifier->verify($this->idp->issueAccessToken());

        self::assertSame('key-2', $jwt->header['kid']);
        self::assertSame(2, $this->idp->requestCount('/jwks'));
    }

    public function testUnknownKeysDoNotMakeTheKeysBeFetchedOverAndOver(): void
    {
        $verifier = $this->jwtVerifier();
        $verifier->verify($this->idp->issueAccessToken());

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                $verifier->verify($this->idp->issueAccessToken([], ['kid' => 'ghost-' . $attempt]));
                self::fail('The token should be refused.');
            } catch (InvalidToken $exception) {
                self::assertSame(TokenError::UnknownKey, $exception->reason);
            }
        }

        // The keys were fetched once to start with; the flood of unknown identifiers caused no other fetch.
        self::assertSame(1, $this->idp->requestCount('/jwks'));

        $this->clock->advance('+31 seconds');
        $this->idp->rotateKeys(keepPrevious: true);

        self::assertSame('key-2', $verifier->verify($this->idp->issueAccessToken())->header['kid']);
        self::assertSame(2, $this->idp->requestCount('/jwks'));
    }

    public function testATokenWithoutKeyIdentifierIsTriedAgainstEveryKeyOfItsType(): void
    {
        $this->idp->rotateKeys(keepPrevious: true);

        self::assertArrayNotHasKey('kid', $this->jwtVerifier()->verify($this->idp->issueAccessToken([], ['kid' => null]))->header);
    }

    public function testEveryAsymmetricAlgorithmIsVerified(): void
    {
        foreach (['RS256', 'PS256', 'ES256', 'ES384', 'EdDSA'] as $algorithm) {
            $key = KeyFactory::generate($algorithm, ['kid' => 'k']);
            $verifier = new JwtVerifier($this->discovery, new KeySet([KeyFactory::toPublic($key)]), [$algorithm], clock: $this->clock);
            $token = $this->idp->sign(['iss' => self::ISSUER, 'exp' => $this->clock->now()->getTimestamp() + 60], [], $key, $algorithm);

            self::assertSame($algorithm, $verifier->verify($token)->algorithm());
        }
    }

    public function testAKeyDeclaredForAnotherAlgorithmIsNotUsed(): void
    {
        $key = KeyFactory::generate('RS256', ['kid' => 'k']);
        $verifier = new JwtVerifier($this->discovery, new KeySet([KeyFactory::toPublic($key)]), ['RS256', 'PS256'], clock: $this->clock);
        // The provider publishes the key for RS256 only; the token is signed with it using PS256.
        $token = $this->idp->sign(['iss' => self::ISSUER, 'exp' => $this->clock->now()->getTimestamp() + 60], [], array_diff_key($key, ['alg' => true]), 'PS256');

        try {
            $verifier->verify($token);
            self::fail('The token should be refused.');
        } catch (InvalidToken $exception) {
            self::assertSame(TokenError::UnknownKey, $exception->reason);
        }
    }

    public function testAnOutageOfTheProviderIsNotARefusal(): void
    {
        $token = $this->idp->issueAccessToken();
        $this->idp->available = false;

        $this->expectException(ProviderUnreachable::class);

        $this->jwtVerifier()->verify($token);
    }

    public function testCachedKeysSurviveAnOutage(): void
    {
        $verifier = $this->jwtVerifier();
        $token = $this->idp->issueAccessToken();
        $verifier->verify($token);

        $this->idp->available = false;

        self::assertSame($token, $verifier->verify($token)->token);
    }

    public function testAnUnavailableAlgorithmIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('"HS256" is not available');

        $this->jwtVerifier(['RS256', 'HS256']);
    }

    public function testMessagesRevealNothingAboutTheConfiguration(): void
    {
        try {
            $this->jwtVerifier()->verify($this->idp->issueAccessToken(['aud' => 'billing']), ['api']);
            self::fail('The token should be refused.');
        } catch (InvalidToken $exception) {
            self::assertSame('The token is not meant for this audience.', $exception->getMessage());
        }
    }
}
