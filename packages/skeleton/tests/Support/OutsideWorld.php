<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Acta\Oidc\Testing\FakeIdentityProvider;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the network: calls to the identity provider reach the fake one, every
 * other call reaches the fake webhook subscriber.
 */
final readonly class OutsideWorld implements ClientInterface
{
    public function __construct(
        private FakeIdentityProvider $identityProvider,
        private FakeSubscriber $subscriber,
    ) {}

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return str_starts_with((string) $request->getUri(), $this->identityProvider->issuer)
            ? $this->identityProvider->sendRequest($request)
            : $this->subscriber->sendRequest($request);
    }
}
