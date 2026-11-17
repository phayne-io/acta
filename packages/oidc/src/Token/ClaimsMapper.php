<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

/**
 * Turns the claims of an access token into a {@see Principal}.
 *
 * Providers disagree on where scopes and roles live; this is where an application
 * adapts to its own.
 */
interface ClaimsMapper
{
    public function map(Claims $claims): Principal;
}
