<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

/**
 * A JSON Web Token whose signature, issuer and validity period were checked.
 */
final readonly class VerifiedJwt
{
    /**
     * @param string               $token  the token as it was presented
     * @param array<string, mixed> $header its protected header
     */
    public function __construct(
        public string $token,
        public array $header,
        public Claims $claims,
    ) {}

    /**
     * The signature algorithm, which was checked against the allowed ones.
     */
    public function algorithm(): string
    {
        return is_string($this->header['alg'] ?? null) ? $this->header['alg'] : '';
    }

    /**
     * The `typ` header in lower case, without its `application/` prefix; null when absent.
     */
    public function type(): ?string
    {
        $type = $this->header['typ'] ?? null;

        return is_string($type) ? preg_replace('~^application/~', '', strtolower($type)) : null;
    }
}
