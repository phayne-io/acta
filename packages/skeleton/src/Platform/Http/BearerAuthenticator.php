<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Acta\Http\Exception\HttpException;
use Acta\Http\OpenApi\SecurityScheme;
use Acta\Http\Security\Authenticator;
use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Server\BearerChallenge;
use Acta\Oidc\Server\BearerToken;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\Principal;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticates the operations the contract protects with a Bearer JWT.
 *
 * It is where `acta/http`, which knows the security requirements of each operation, meets
 * `acta/oidc`, which knows how to verify a token against the keys of the identity
 * provider. The scopes an operation requires are those of its `security` entry in the
 * contract; nothing about them is written in PHP.
 *
 * Handlers read the caller with `Principal::from($request)`.
 */
final readonly class BearerAuthenticator implements Authenticator
{
    public function __construct(
        private AccessTokenVerifier $verifier,
    ) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request, SecurityScheme $scheme, array $scopes): ServerRequestInterface
    {
        $token = BearerToken::fromRequest($request)
            ?? throw HttpException::unauthorized('A Bearer access token is required.', BearerChallenge::missingToken());

        try {
            $principal = $this->verifier->verify($token);
        } catch (InvalidToken $exception) {
            throw HttpException::unauthorized($exception->getMessage(), BearerChallenge::invalidToken($exception->getMessage()));
        }

        if ($principal->missingScopes($scopes) !== []) {
            throw HttpException::forbidden('The access token lacks a required scope.', BearerChallenge::insufficientScope($scopes));
        }

        return $request->withAttribute(Principal::class, $principal);
    }
}
