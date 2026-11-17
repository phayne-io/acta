<?php

declare(strict_types=1);

namespace Acta\Http\Security;

use Acta\Http\Exception\HttpException;
use Acta\Http\OpenApi\SecurityScheme;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Checks the credentials of a request for one security scheme of the contract.
 *
 * This is the port through which authentication is plugged in: a Bearer JWT verifier,
 * an API key lookup, a session. The contract says which scheme and which scopes each
 * operation needs; the authenticator says whether the request has them.
 */
interface Authenticator
{
    /**
     * Authenticates the request and returns it, usually with the authenticated party
     * added as an attribute for the handlers.
     *
     * @param SecurityScheme $scheme the scheme as the contract declares it
     * @param list<string>   $scopes scopes the operation requires for this scheme
     *
     * @throws HttpException 401 when the credentials are missing or invalid ({@see HttpException::unauthorized()}),
     *                       403 when they are valid but insufficient ({@see HttpException::forbidden()})
     */
    public function authenticate(ServerRequestInterface $request, SecurityScheme $scheme, array $scopes): ServerRequestInterface;
}
