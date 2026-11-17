<?php

declare(strict_types=1);

namespace Acta\Oidc\Server;

/**
 * Builds the `WWW-Authenticate` header values of RFC 6750, section 3.
 */
final class BearerChallenge
{
    /**
     * The request carried no token: the challenge names no error.
     */
    public static function missingToken(?string $realm = null): string
    {
        return self::build($realm, []);
    }

    /**
     * The token was refused: answer 401.
     *
     * @param string $description why, in words safe to show to the client
     */
    public static function invalidToken(string $description, ?string $realm = null): string
    {
        return self::build($realm, ['error' => 'invalid_token', 'error_description' => $description]);
    }

    /**
     * The token is valid but was not granted what the request needs: answer 403.
     *
     * @param list<string> $scopes scopes the request needs
     */
    public static function insufficientScope(array $scopes, ?string $realm = null): string
    {
        return self::build($realm, ['error' => 'insufficient_scope', 'scope' => implode(' ', $scopes)]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function build(?string $realm, array $parameters): string
    {
        $parameters = $realm === null ? $parameters : ['realm' => $realm, ...$parameters];
        $pairs = [];

        foreach ($parameters as $name => $value) {
            // Quoted strings of RFC 6750 allow neither quotes, backslashes nor control characters.
            $pairs[] = sprintf('%s="%s"', $name, preg_replace('/[^\x20\x21\x23-\x5B\x5D-\x7E]/', '', $value));
        }

        return rtrim('Bearer ' . implode(', ', $pairs));
    }
}
