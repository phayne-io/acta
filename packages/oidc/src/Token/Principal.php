<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The party a verified access token speaks for.
 *
 * {@see \Acta\Oidc\Server\BearerAuthMiddleware} stores it as a request attribute.
 */
final readonly class Principal
{
    /**
     * @param string|null  $subject  the `sub` claim: the user, or the client itself for a client credentials token
     * @param list<string> $scopes   scopes granted to the token
     * @param list<string> $roles    roles of the subject, when the provider puts them in the token
     * @param string|null  $clientId the client the token was issued to
     * @param Claims       $claims   every claim of the token
     */
    public function __construct(
        public ?string $subject,
        public array $scopes,
        public array $roles,
        public ?string $clientId,
        public Claims $claims,
    ) {}

    /**
     * Returns the principal of an authenticated request.
     *
     * @throws LogicException when the request carries none
     */
    public static function from(ServerRequestInterface $request): self
    {
        return self::tryFrom($request)
            ?? throw new LogicException('This request is not authenticated: no Principal attribute was set.');
    }

    public static function tryFrom(ServerRequestInterface $request): ?self
    {
        $principal = $request->getAttribute(self::class);

        return $principal instanceof self ? $principal : null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Returns the scopes of the list the token was not granted.
     *
     * @param list<string> $required
     *
     * @return list<string>
     */
    public function missingScopes(array $required): array
    {
        return array_values(array_diff($required, $this->scopes));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
