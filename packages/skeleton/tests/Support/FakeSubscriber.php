<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Laminas\Diactoros\Response;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A webhook subscriber that keeps what it receives.
 */
final class FakeSubscriber implements ClientInterface
{
    /** @var list<RequestInterface> */
    public private(set) array $requests = [];

    /** Status the subscriber answers with. */
    public int $status = 204;

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response('php://memory', $this->status);
    }

    /**
     * Returns the notifications received, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(): array
    {
        return array_map(
            static fn(RequestInterface $request): array => (array) json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
            $this->requests,
        );
    }
}
