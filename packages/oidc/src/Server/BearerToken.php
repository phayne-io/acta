<?php

declare(strict_types=1);

namespace Acta\Oidc\Server;

use Psr\Http\Message\RequestInterface;

/**
 * Reads the Bearer token of a request (RFC 6750, section 2.1).
 *
 * Tokens in the query string or in a form body are deliberately not supported: they
 * end up in logs and browser histories.
 */
final class BearerToken
{
    /**
     * Returns the token of the `Authorization` header, or null when the request has
     * none or uses another scheme.
     */
    public static function fromRequest(RequestInterface $request): ?string
    {
        return preg_match('~^Bearer +([A-Za-z0-9\-._\~+/]+=*)$~i', trim($request->getHeaderLine('Authorization')), $matches) === 1
            ? $matches[1]
            : null;
    }
}
