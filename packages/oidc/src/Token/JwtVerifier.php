<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

use Acta\Oidc\Discovery\MetadataSource;
use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Jose\SignatureVerifier;
use Acta\Oidc\Jose\WebTokenJose;
use Acta\Oidc\Jwks\KeySource;
use Acta\Oidc\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Verifies JSON Web Tokens issued by the provider.
 *
 * In order: the token is well-formed; its algorithm is one of those allowed here, which
 * never include `none` or a shared-secret algorithm; a key of the provider verifies
 * its signature; the issuer is the provider; the token has not expired and is already
 * valid; and, when asked, it is meant for one of the given audiences.
 *
 * Nothing in the token is trusted before its signature has been verified.
 */
final readonly class JwtVerifier
{
    /** Tokens above this size are refused before being parsed. */
    private const int MAX_LENGTH = 16_384;

    /**
     * @param list<string> $algorithms signature algorithms accepted, whatever the token claims
     * @param int          $leeway     seconds of clock difference tolerated with the provider
     *
     * @throws ConfigurationError when an algorithm is not available
     */
    public function __construct(
        private MetadataSource $provider,
        private KeySource $keys,
        private array $algorithms = ['RS256', 'PS256', 'ES256', 'EdDSA'],
        private int $leeway = 30,
        private ClockInterface $clock = new SystemClock(),
        private SignatureVerifier $signatures = new WebTokenJose(),
    ) {
        foreach ($algorithms as $algorithm) {
            // EdDSA is in the default list and simply stays unused without the sodium extension.
            if (!$signatures->supports($algorithm) && $algorithm !== 'EdDSA') {
                throw new ConfigurationError(sprintf('The signature algorithm "%s" is not available.', $algorithm));
            }
        }
    }

    /**
     * @param list<string> $audiences the token must be meant for at least one of them; not checked when empty
     *
     * @throws InvalidToken        when the token is refused
     * @throws ProviderUnreachable when the metadata or the keys of the provider cannot be fetched
     */
    public function verify(string $token, array $audiences = []): VerifiedJwt
    {
        $parts = explode('.', $token);

        if (strlen($token) > self::MAX_LENGTH || count($parts) !== 3 || in_array('', $parts, true)) {
            throw new InvalidToken(TokenError::Malformed, 'The token is not a signed JSON Web Token.');
        }

        $header = self::decode($parts[0]);
        $algorithm = $header['alg'] ?? null;

        if (!is_string($algorithm) || !in_array($algorithm, $this->algorithms, true) || !$this->signatures->supports($algorithm)) {
            throw new InvalidToken(TokenError::UnsupportedAlgorithm, 'The signature algorithm of the token is not accepted.');
        }

        if (isset($header['crit'])) {
            throw new InvalidToken(TokenError::Malformed, 'The token requires extensions that are not supported.');
        }

        $this->verifySignature($token, is_string($header['kid'] ?? null) ? $header['kid'] : null, $algorithm);

        $claims = new Claims(self::decode($parts[1]));
        $now = $this->clock->now()->getTimestamp();

        if ($claims->string('iss') !== $this->provider->metadata()->issuer) {
            throw new InvalidToken(TokenError::WrongIssuer, 'The token was not issued by the expected provider.');
        }

        $expiry = $claims->int('exp') ?? throw new InvalidToken(TokenError::MissingClaim, 'The token has no expiry.');

        if ($now >= $expiry + $this->leeway) {
            throw new InvalidToken(TokenError::Expired, 'The token has expired.');
        }

        if (max($claims->int('nbf') ?? 0, $claims->int('iat') ?? 0) > $now + $this->leeway) {
            throw new InvalidToken(TokenError::NotYetValid, 'The token is not valid yet.');
        }

        if ($audiences !== [] && array_intersect($claims->strings('aud'), $audiences) === []) {
            throw new InvalidToken(TokenError::WrongAudience, 'The token is not meant for this audience.');
        }

        return new VerifiedJwt($token, $header, $claims);
    }

    private function verifySignature(string $token, ?string $keyId, string $algorithm): void
    {
        $candidates = $this->keys->keys()->candidates($keyId, $algorithm);

        if ($candidates === []) {
            // The provider may have rotated its keys since they were cached.
            $candidates = $this->keys->refresh()?->candidates($keyId, $algorithm) ?? [];
        }

        if ($candidates === []) {
            throw new InvalidToken(TokenError::UnknownKey, 'The token is signed with a key the provider does not publish.');
        }

        if (!array_any($candidates, fn(array $key): bool => $this->signatures->verify($token, $key, $algorithm))) {
            throw new InvalidToken(TokenError::BadSignature, 'The signature of the token is not valid.');
        }
    }

    /**
     * Decodes one base64url-encoded JSON object of the token.
     *
     * @return array<string, mixed>
     */
    private static function decode(string $part): array
    {
        $json = preg_match('/^[A-Za-z0-9_-]+$/', $part) === 1 ? base64_decode(strtr($part, '-_', '+/'), true) : false;
        $decoded = $json === false ? null : json_decode($json, true, 32);

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidToken(TokenError::Malformed, 'The token is not a signed JSON Web Token.');
        }

        $object = [];

        foreach ($decoded as $name => $value) {
            $object[(string) $name] = $value;
        }

        return $object;
    }
}
