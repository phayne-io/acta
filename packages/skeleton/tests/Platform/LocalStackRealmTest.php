<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The Keycloak realm of the local stack, `docker/keycloak/realm.json`.
 *
 * Keycloak imports the file without complaining about what it lacks, and the lack only
 * shows when someone logs in: these are the mistakes it let through once.
 */
#[CoversNothing]
final class LocalStackRealmTest extends TestCase
{
    /**
     * A realm that declares client scopes gets those and no other: Keycloak then leaves
     * out its own (`profile`, `email`, `roles`, `basic`), and a client that names one of
     * them is given tokens without it, silently.
     */
    public function testEveryScopeAClientNamesIsDeclared(): void
    {
        $realm = self::realm();
        // The only scope Keycloak creates whatever the file says.
        $declared = ['offline_access', ...array_column(self::records($realm, 'clientScopes'), 'name')];

        foreach (self::records($realm, 'clients') as $client) {
            self::assertIsString($client['clientId'] ?? null);

            foreach (['defaultClientScopes', 'optionalClientScopes'] as $kind) {
                self::assertIsArray($client[$kind] ?? null, sprintf('%s lists no %s.', $client['clientId'], $kind));
                self::assertSame(
                    [],
                    array_values(array_diff($client[$kind], $declared)),
                    sprintf('%s names %s the realm does not declare.', $client['clientId'], $kind),
                );
            }
        }
    }

    /**
     * What the console and the API read from tokens: who logged in, and their roles.
     */
    public function testThePersonAndTheirRolesReachTheTokens(): void
    {
        $claims = [];

        foreach (self::records(self::realm(), 'clientScopes') as $scope) {
            foreach (self::records($scope, 'protocolMappers') as $mapper) {
                $config = is_array($mapper['config'] ?? null) ? $mapper['config'] : [];
                $claims[] = $mapper['protocolMapper'] === 'oidc-sub-mapper' ? 'sub' : $config['claim.name'] ?? null;
            }
        }

        foreach (['sub', 'preferred_username', 'email', 'realm_access.roles'] as $claim) {
            self::assertContains($claim, $claims);
        }
    }

    /**
     * An imported user only has the roles the file gives them. Without the default roles
     * of the realm, which hold `offline_access`, Keycloak approves the login of the
     * console and then refuses the refresh token it asks for.
     */
    public function testUsersHaveTheDefaultRolesOfTheRealm(): void
    {
        $users = self::records(self::realm(), 'users');

        self::assertNotSame([], $users);

        foreach ($users as $user) {
            self::assertIsString($user['username'] ?? null);
            self::assertIsArray($user['realmRoles'] ?? null, sprintf('%s has no realm role.', $user['username']));
            self::assertContains('default-roles-${APP_REALM}', $user['realmRoles']);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function realm(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/docker/keycloak/realm.json');
        self::assertIsString($json);

        $realm = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($realm);

        return $realm;
    }

    /**
     * The objects listed under a key, such as the clients of the realm.
     *
     * @param array<array-key, mixed> $from
     *
     * @return list<array<array-key, mixed>>
     */
    private static function records(array $from, string $key): array
    {
        $records = $from[$key] ?? [];
        self::assertIsArray($records);

        return array_values(array_filter($records, is_array(...)));
    }
}
