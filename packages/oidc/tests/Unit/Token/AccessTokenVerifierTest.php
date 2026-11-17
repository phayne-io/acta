<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Token;

use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Tests\OidcTestCase;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\Claims;
use Acta\Oidc\Token\ClaimsMapper;
use Acta\Oidc\Token\Principal;
use Acta\Oidc\Token\StandardClaimsMapper;
use Acta\Oidc\Token\TokenError;
use LogicException;
use Override;

final class AccessTokenVerifierTest extends OidcTestCase
{
    public function testAVerifiedTokenBecomesAPrincipal(): void
    {
        $token = $this->idp->issueAccessToken([
            'sub' => 'ada',
            'client_id' => 'web',
            'scope' => 'accounts:read  accounts:write',
            'roles' => ['teller'],
        ]);

        $principal = new AccessTokenVerifier($this->jwtVerifier(), 'api')->verify($token);

        self::assertSame('ada', $principal->subject);
        self::assertSame('web', $principal->clientId);
        self::assertSame(['accounts:read', 'accounts:write'], $principal->scopes);
        self::assertSame(['teller'], $principal->roles);
        self::assertTrue($principal->hasScope('accounts:write'));
        self::assertFalse($principal->hasScope('accounts'));
        self::assertTrue($principal->hasRole('teller'));
        self::assertFalse($principal->hasRole('admin'));
        self::assertSame(['admin'], $principal->missingScopes(['accounts:read', 'admin']));
        self::assertSame([], $principal->missingScopes([]));
        self::assertSame('ada', $principal->claims->string('sub'));
    }

    public function testScopesAndRolesAreReadWhereProvidersPutThem(): void
    {
        $mapper = new StandardClaimsMapper(roleClaims: ['roles', 'realm_access.roles', 'resource_access.banking.roles', 'https://example.test/roles']);

        // Keycloak
        $keycloak = $mapper->map(new Claims([
            'sub' => 'ada',
            'azp' => 'banking',
            'scope' => 'openid profile',
            'realm_access' => ['roles' => ['offline_access', 'teller']],
            'resource_access' => ['banking' => ['roles' => ['teller', 'auditor']], 'account' => ['roles' => ['view-profile']]],
        ]));

        self::assertSame(['openid', 'profile'], $keycloak->scopes);
        self::assertSame(['offline_access', 'teller', 'auditor'], $keycloak->roles);
        self::assertSame('banking', $keycloak->clientId);

        // Microsoft Entra ID
        $entra = $mapper->map(new Claims(['sub' => 'ada', 'scp' => 'Accounts.Read Accounts.Write', 'roles' => ['Teller']]));

        self::assertSame(['Accounts.Read', 'Accounts.Write'], $entra->scopes);
        self::assertSame(['Teller'], $entra->roles);

        // Auth0: an array of scopes, and roles in a namespaced claim whose name holds dots
        $auth0 = $mapper->map(new Claims(['scope' => ['read', 'write', 7], 'https://example.test/roles' => ['teller']]));

        self::assertSame(['read', 'write'], $auth0->scopes);
        self::assertSame(['teller'], $auth0->roles);
        self::assertNull($auth0->subject);
        self::assertNull($auth0->clientId);
    }

    public function testAnApplicationCanMapClaimsItsOwnWay(): void
    {
        $mapper = new class implements ClaimsMapper {
            #[Override]
            public function map(Claims $claims): Principal
            {
                return new Principal($claims->string('email'), [], $claims->strings('groups'), null, $claims);
            }
        };
        $token = $this->idp->issueAccessToken(['email' => 'ada@example.test', 'groups' => ['tellers']]);

        $principal = new AccessTokenVerifier($this->jwtVerifier(), 'api', $mapper)->verify($token);

        self::assertSame('ada@example.test', $principal->subject);
        self::assertSame(['tellers'], $principal->roles);
    }

    public function testTheTokenMustBeMeantForThisApi(): void
    {
        $token = $this->idp->issueAccessToken(['aud' => ['billing', 'reporting']]);

        self::assertSame('user-1', new AccessTokenVerifier($this->jwtVerifier(), ['api', 'reporting'])->verify($token)->subject);
        self::assertSame('user-1', new AccessTokenVerifier($this->jwtVerifier(), [])->verify($token)->subject);

        $this->expectException(InvalidToken::class);

        new AccessTokenVerifier($this->jwtVerifier(), 'api')->verify($token);
    }

    public function testAnIdTokenIsNotAnAccessToken(): void
    {
        $verifier = new AccessTokenVerifier($this->jwtVerifier(), 'api');
        $strict = new AccessTokenVerifier($this->jwtVerifier(), 'api', requireTokenType: true);

        // Tokens typed otherwise are refused; untyped and plain JWT ones are only accepted in the lenient mode.
        $verifier->verify($this->idp->issueAccessToken([], ['typ' => null]));
        $verifier->verify($this->idp->issueAccessToken([], ['typ' => 'JWT']));
        $strict->verify($this->idp->issueAccessToken([], ['typ' => 'application/at+jwt']));

        foreach ([[$verifier, 'id+jwt'], [$strict, 'JWT'], [$strict, null]] as [$subject, $type]) {
            try {
                $subject->verify($this->idp->issueAccessToken([], ['typ' => $type]));
                self::fail('The token should be refused.');
            } catch (InvalidToken $exception) {
                self::assertSame(TokenError::WrongType, $exception->reason);
            }
        }
    }

    public function testClaimsAreReadByTypeAndByPath(): void
    {
        $claims = new Claims([
            'sub' => 'ada',
            'exp' => 1767225600,
            'rate' => 1767225600.0,
            'flag' => true,
            'empty' => null,
            'nested' => ['deep' => ['value' => 'x', 'list' => ['a', 1, 'b']]],
            'scope' => ' a  b ',
        ]);

        self::assertTrue($claims->has('empty'));
        self::assertFalse($claims->has('missing'));
        self::assertSame('ada', $claims->get('sub'));
        self::assertSame('fallback', $claims->get('missing', 'fallback'));
        self::assertNull($claims->string('exp'));
        self::assertSame(1767225600, $claims->int('exp'));
        self::assertSame(1767225600, $claims->int('rate'));
        self::assertNull($claims->int('sub'));
        self::assertSame('x', $claims->path('nested.deep.value'));
        self::assertNull($claims->path('nested.missing.value'));
        self::assertNull($claims->path('sub.deeper'));
        self::assertSame(['a', 'b'], $claims->strings('nested.deep.list'));
        self::assertSame(['a', 'b'], $claims->strings('scope'));
        self::assertSame([], $claims->strings('flag'));
    }

    public function testThePrincipalTravelsWithTheRequest(): void
    {
        $principal = new Principal('ada', [], [], null, new Claims([]));
        $request = self::serverRequest();

        self::assertNull(Principal::tryFrom($request));
        self::assertSame($principal, Principal::from($request->withAttribute(Principal::class, $principal)));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not authenticated');

        Principal::from($request);
    }
}
