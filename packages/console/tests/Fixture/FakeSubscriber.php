<?php

declare(strict_types=1);

namespace Acta\Console\Tests\Fixture;

use Laminas\Diactoros\Response;
use Override;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client standing in for the endpoints of the subscribers.
 */
final class FakeSubscriber implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $received = [];

    /** @var list<int|null> statuses answered to the next requests, null for a network failure; 204 once exhausted */
    public array $answers = [];

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->received[] = $request;
        $status = $this->answers === [] ? 204 : array_shift($this->answers);

        if ($status === null) {
            throw new class ('Connection timed out') extends RuntimeException implements ClientExceptionInterface {};
        }

        return new Response('php://memory', $status);
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(static fn(RequestInterface $request): string => (string) $request->getUri(), $this->received);
    }
}
