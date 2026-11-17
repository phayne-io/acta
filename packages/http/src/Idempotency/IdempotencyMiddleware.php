<?php

declare(strict_types=1);

namespace Acta\Http\Idempotency;

use Acta\Http\Exception\HttpException;
use Acta\Http\SystemClock;
use Closure;
use DateInterval;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Honours the `Idempotency-Key` header: a request repeated with the same key is
 * answered with the stored response of the first one, without being handled again.
 *
 * - the same key with a different request is rejected with 422;
 * - the same key while the first request is still being handled is rejected with 409;
 * - server errors are not stored, so that the client can retry.
 *
 * Place it after authentication and validation, right before the handlers. The stored
 * response and the effects of the handler are not written atomically: if the process
 * dies between the two, the key is still reserved and retries are answered 409 until
 * it expires.
 */
final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'Idempotency-Key';

    /** Header added to a response that was replayed rather than produced. */
    public const string REPLAYED_HEADER = 'Idempotency-Replayed';

    /**
     * @param int                                            $ttl     seconds a key is remembered
     * @param list<string>                                   $methods methods the header is honoured on
     * @param (Closure(ServerRequestInterface): string)|null $scope   returns what keys are scoped to, typically the
     *                                                                authenticated caller, so that two callers may
     *                                                                use the same key
     */
    public function __construct(
        private IdempotencyStore $store,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private ClockInterface $clock = new SystemClock(),
        private int $ttl = 86400,
        private array $methods = ['POST'],
        private ?Closure $scope = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), $this->methods, true) || !$request->hasHeader(self::HEADER)) {
            return $handler->handle($request);
        }

        $header = trim($request->getHeaderLine(self::HEADER), " \t\"");

        if ($header === '' || strlen($header) > 255) {
            throw HttpException::badRequest('The Idempotency-Key header must hold between 1 and 255 characters.');
        }

        $key = hash('sha256', ($this->scope?->__invoke($request) ?? '') . "\0" . $header);
        $fingerprint = hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            (string) $request->getBody(),
        ]));
        $now = $this->clock->now();
        $record = $this->store->acquire($key, $fingerprint, $now, $now->add(new DateInterval(sprintf('PT%dS', $this->ttl))));

        if ($record !== null) {
            return $this->replay($record, $fingerprint);
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $exception) {
            $this->store->release($key);

            throw $exception;
        }

        if ($response->getStatusCode() >= 500) {
            $this->store->release($key);

            return $response;
        }

        $body = $response->getBody();
        $this->store->complete($key, new StoredResponse($response->getStatusCode(), self::headers($response), (string) $body));

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $response;
    }

    private function replay(IdempotencyRecord $record, string $fingerprint): ResponseInterface
    {
        if (!hash_equals($record->fingerprint, $fingerprint)) {
            throw HttpException::unprocessable('This Idempotency-Key was already used for a different request.');
        }

        $stored = $record->response
            ?? throw HttpException::conflict('A request with this Idempotency-Key is still being handled.');
        $response = $this->responses->createResponse($stored->status)->withHeader(self::REPLAYED_HEADER, 'true');

        foreach ($stored->headers as $name => $values) {
            $response = $response->withHeader((string) $name, $values);
        }

        return $stored->body === '' ? $response : $response->withBody($this->streams->createStream($stored->body));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function headers(ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return $headers;
    }
}
