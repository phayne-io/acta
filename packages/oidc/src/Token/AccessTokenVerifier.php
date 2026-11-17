<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Exception\ProviderUnreachable;

/**
 * Verifies the JWT access tokens a resource server receives and tells who they speak for.
 */
final readonly class AccessTokenVerifier
{
    /** @var list<string> */
    private array $audiences;

    /**
     * @param string|list<string> $audience         identifier(s) of this API; a token must be meant for one of
     *                                              them. An empty list skips the check: only do so when the
     *                                              provider issues tokens for this API alone
     * @param bool                $requireTokenType refuses tokens whose `typ` header is not `at+jwt` (RFC 9068).
     *                                              Turn it on when the provider sets it: it is what tells an
     *                                              access token from an ID token
     */
    public function __construct(
        private JwtVerifier $verifier,
        string|array $audience,
        private ClaimsMapper $mapper = new StandardClaimsMapper(),
        private bool $requireTokenType = false,
    ) {
        $this->audiences = is_string($audience) ? [$audience] : $audience;
    }

    /**
     * @throws InvalidToken        when the token is refused
     * @throws ProviderUnreachable when the metadata or the keys of the provider cannot be fetched
     */
    public function verify(string $token): Principal
    {
        $jwt = $this->verifier->verify($token, $this->audiences);
        $type = $jwt->type();

        if ($this->requireTokenType ? $type !== 'at+jwt' : !in_array($type, [null, 'at+jwt', 'jwt'], true)) {
            throw new InvalidToken(TokenError::WrongType, 'The token is not an access token.');
        }

        return $this->mapper->map($jwt->claims);
    }
}
