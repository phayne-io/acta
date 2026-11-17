<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Token\Claims;
use DateTimeImmutable;
use DateTimeInterface;
use SensitiveParameter;

/**
 * What the token endpoint answered.
 */
final readonly class TokenSet
{
    /**
     * @param DateTimeImmutable|null $expiresAt     when the access token expires; null when the provider did not say
     * @param list<string>           $scopes        scopes granted, when the provider lists them
     * @param Claims|null            $idTokenClaims claims of the ID token, once it has been verified
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        public string $tokenType = 'Bearer',
        public ?DateTimeImmutable $expiresAt = null,
        #[SensitiveParameter]
        public ?string $refreshToken = null,
        #[SensitiveParameter]
        public ?string $idToken = null,
        public array $scopes = [],
        public ?Claims $idTokenClaims = null,
    ) {}

    /**
     * Reads a successful response of the token endpoint.
     *
     * @param array<string, mixed> $response
     * @param DateTimeImmutable    $now      when the response was received
     *
     * @throws ProviderUnreachable when the response has no access token
     */
    public static function fromResponse(array $response, DateTimeImmutable $now): self
    {
        $string = static fn(string $name): ?string => is_string($response[$name] ?? null) && $response[$name] !== ''
            ? $response[$name]
            : null;
        $lifetime = $response['expires_in'] ?? null;

        return new self(
            $string('access_token') ?? throw new ProviderUnreachable('The token endpoint answered without access token.'),
            $string('token_type') ?? 'Bearer',
            is_numeric($lifetime) ? $now->modify(sprintf('+%d seconds', (int) $lifetime)) : null,
            $string('refresh_token'),
            $string('id_token'),
            array_values(array_filter(explode(' ', $string('scope') ?? ''), static fn(string $scope): bool => $scope !== '')),
        );
    }

    /**
     * Rebuilds a token set from {@see self::toArray()}.
     *
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): ?self
    {
        $string = static fn(string $name): ?string => is_string($values[$name] ?? null) ? $values[$name] : null;
        $accessToken = $string('access_token');
        $expiresAt = $string('expires_at');
        $claims = $values['id_token_claims'] ?? null;

        if ($accessToken === null) {
            return null;
        }

        $idTokenClaims = [];

        foreach (is_array($claims) ? $claims : [] as $name => $value) {
            $idTokenClaims[(string) $name] = $value;
        }

        return new self(
            $accessToken,
            $string('token_type') ?? 'Bearer',
            $expiresAt === null ? null : (DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $expiresAt) ?: null),
            $string('refresh_token'),
            $string('id_token'),
            array_values(array_filter(is_array($values['scopes'] ?? null) ? $values['scopes'] : [], is_string(...))),
            is_array($claims) ? new Claims($idTokenClaims) : null,
        );
    }

    /**
     * Returns the token set as plain data, to keep it in a cache, a session or a file.
     * It holds secrets: store it accordingly.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::RFC3339),
            'refresh_token' => $this->refreshToken,
            'id_token' => $this->idToken,
            'scopes' => $this->scopes,
            'id_token_claims' => $this->idTokenClaims?->values,
        ];
    }

    /**
     * Tells whether the access token expires within the given number of seconds.
     * A token without known expiry is considered usable.
     */
    public function expiresWithin(int $seconds, DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->getTimestamp() - $now->getTimestamp() <= $seconds;
    }

    /**
     * Returns a copy with the verified claims of its ID token.
     */
    public function withIdTokenClaims(Claims $claims): self
    {
        return clone($this, ['idTokenClaims' => $claims]);
    }

    /**
     * Returns a copy keeping this refresh token when the new set came without one,
     * which is what providers that do not rotate refresh tokens answer.
     */
    public function refreshedBy(self $fresh): self
    {
        return $fresh->refreshToken === null ? clone($fresh, ['refreshToken' => $this->refreshToken]) : $fresh;
    }
}
