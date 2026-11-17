<?php

declare(strict_types=1);

namespace Acta\Http\Response;

use Acta\Http\Problem\ProblemDetails;
use NoDiscard;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds responses with the PSR-17 factories, so that handlers depend on no PSR-7 implementation.
 */
final readonly class Responder
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * @param mixed                 $data    anything `json_encode()` accepts
     * @param array<string, string> $headers
     */
    #[NoDiscard]
    public function json(mixed $data, int $status = 200, array $headers = [], string $contentType = 'application/json'): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streams->createStream(json_encode($data, self::JSON_FLAGS)));

        return self::withHeaders($response, $headers);
    }

    /**
     * Answers 201 with the location of the created resource.
     *
     * @param mixed                 $data    representation of the resource; no body when null
     * @param array<string, string> $headers
     */
    #[NoDiscard]
    public function created(string $location, mixed $data = null, array $headers = []): ResponseInterface
    {
        $headers = ['Location' => $location, ...$headers];

        return $data === null ? $this->empty(201, $headers) : $this->json($data, 201, $headers);
    }

    /**
     * Answers 202: the request was accepted and its effects may not be visible yet.
     *
     * @param mixed                 $data    no body when null
     * @param array<string, string> $headers
     */
    #[NoDiscard]
    public function accepted(mixed $data = null, array $headers = []): ResponseInterface
    {
        return $data === null ? $this->empty(202, $headers) : $this->json($data, 202, $headers);
    }

    /**
     * Answers without body, 204 by default.
     *
     * @param array<string, string> $headers
     */
    #[NoDiscard]
    public function empty(int $status = 204, array $headers = []): ResponseInterface
    {
        return self::withHeaders($this->responses->createResponse($status), $headers);
    }

    /**
     * Answers with a problem document (RFC 9457).
     *
     * @param array<string, string> $headers
     */
    #[NoDiscard]
    public function problem(ProblemDetails $problem, array $headers = []): ResponseInterface
    {
        return $this->json($problem, $problem->status, $headers, ProblemDetails::MEDIA_TYPE);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function withHeaders(ResponseInterface $response, array $headers): ResponseInterface
    {
        foreach ($headers as $name => $value) {
            $response = $response->withHeader((string) $name, $value);
        }

        return $response;
    }
}
