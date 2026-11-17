<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client adding a Bearer access token to every request it sends.
 *
 * Give it to the secondary adapters calling protected services: they stay unaware of
 * OAuth. When a service answers 401, the token is renewed and the request sent once
 * more, which covers tokens revoked before their expiry.
 */
final readonly class AuthenticatedHttpClient implements ClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private TokenProvider $tokens,
    ) {}

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->client->sendRequest($request->withHeader('Authorization', 'Bearer ' . $this->tokens->accessToken()));

        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        $body = $request->getBody();

        if ($body->getSize() !== 0 && !$body->isSeekable()) {
            // The body was consumed by the first attempt and cannot be sent again.
            return $response;
        }

        $body->rewind();
        $this->tokens->invalidate();

        return $this->client->sendRequest($request->withHeader('Authorization', 'Bearer ' . $this->tokens->accessToken()));
    }
}
