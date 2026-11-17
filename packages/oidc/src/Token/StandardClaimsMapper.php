<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

use Override;

/**
 * Reads scopes and roles from configurable claims.
 *
 * The defaults cover the common providers: scopes in `scope` (RFC 9068, Keycloak,
 * Auth0) or `scp` (Microsoft Entra ID, Okta); roles in `roles` or, for Keycloak, in
 * `realm_access.roles`. For the roles of one Keycloak client, add
 * `resource_access.<client>.roles`.
 */
final readonly class StandardClaimsMapper implements ClaimsMapper
{
    /**
     * @param list<string> $scopeClaims claims holding scopes, as a space-separated string or an array
     * @param list<string> $roleClaims  claims holding roles; dots reach into nested objects
     */
    public function __construct(
        private array $scopeClaims = ['scope', 'scp'],
        private array $roleClaims = ['roles', 'realm_access.roles'],
    ) {}

    #[Override]
    public function map(Claims $claims): Principal
    {
        return new Principal(
            $claims->string('sub'),
            self::collect($claims, $this->scopeClaims),
            self::collect($claims, $this->roleClaims),
            $claims->string('client_id') ?? $claims->string('azp'),
            $claims,
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function collect(Claims $claims, array $paths): array
    {
        $values = [];

        foreach ($paths as $path) {
            $values = [...$values, ...$claims->strings($path)];
        }

        return array_values(array_unique($values));
    }
}
