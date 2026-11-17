<?php

declare(strict_types=1);

namespace Acta\Oidc\Http;

use Acta\Oidc\Exception\ProviderError;
use Acta\Oidc\Exception\ProviderUnreachable;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * How the package talks to the identity provider: a PSR-18 client and the PSR-17
 * factories to build its requests.
 *
 * Give it an HTTP client with timeouts: every request made here blocks whoever is
 * waiting for a token to be verified or obtained.
 */
final readonly class Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * Fetches a JSON object.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws ProviderError       when the provider answers with an OAuth 2.0 error
     * @throws ProviderUnreachable when the request fails or the answer is not a JSON object
     */
    public function get(string $url, array $headers = []): array
    {
        $request = $this->requests->createRequest('GET', $url)->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return self::decode($url, $this->client->sendRequest($request));
        } catch (ClientExceptionInterface $exception) {
            throw new ProviderUnreachable(sprintf('%s cannot be reached: %s', $url, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * Posts a form and reads the JSON object answered.
     *
     * @param array<string, string> $form
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws ProviderError       when the provider answers with an OAuth 2.0 error
     * @throws ProviderUnreachable when the request fails or the answer is not a JSON object
     */
    public function post(string $url, array $form, array $headers = []): array
    {
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream(http_build_query($form, '', '&', PHP_QUERY_RFC1738)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return self::decode($url, $this->client->sendRequest($request));
        } catch (ClientExceptionInterface $exception) {
            throw new ProviderUnreachable(sprintf('%s cannot be reached: %s', $url, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $url, ResponseInterface $response): array
    {
        $status = $response->getStatusCode();

        try {
            $decoded = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        $body = [];

        foreach (is_array($decoded) && !array_is_list($decoded) ? $decoded : [] as $key => $value) {
            $body[(string) $key] = $value;
        }

        if ($status >= 400 && is_string($body['error'] ?? null)) {
            throw new ProviderError(
                $body['error'],
                is_string($body['error_description'] ?? null) ? $body['error_description'] : null,
                is_string($body['error_uri'] ?? null) ? $body['error_uri'] : null,
                $status,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new ProviderUnreachable(sprintf('%s answered with status %d.', $url, $status));
        }

        if ($body === []) {
            throw new ProviderUnreachable(sprintf('%s did not answer with a JSON object.', $url));
        }

        return $body;
    }
}
