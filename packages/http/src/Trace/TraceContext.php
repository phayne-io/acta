<?php

declare(strict_types=1);

namespace Acta\Http\Trace;

use Psr\Http\Message\ServerRequestInterface;

/**
 * W3C Trace Context of a request: the trace it belongs to and the span handling it.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final readonly class TraceContext
{
    /**
     * @param string      $traceId  32 lower-case hexadecimal characters, shared by the whole trace
     * @param string      $spanId   16 lower-case hexadecimal characters, identifying this request
     * @param string|null $parentId span of the caller, when the request came with a trace
     * @param string      $flags    2 hexadecimal characters; `01` when the trace is sampled
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentId = null,
        public string $flags = '00',
    ) {}

    /**
     * Starts a new trace.
     */
    public static function start(): self
    {
        return new self(bin2hex(random_bytes(16)), bin2hex(random_bytes(8)));
    }

    /**
     * Continues the trace a `traceparent` header describes, or returns null when the
     * header is not valid.
     */
    public static function continue(string $traceparent): ?self
    {
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', trim($traceparent), $matches) !== 1) {
            return null;
        }

        if ($matches[1] === str_repeat('0', 32) || $matches[2] === str_repeat('0', 16)) {
            return null;
        }

        return new self($matches[1], bin2hex(random_bytes(8)), $matches[2], $matches[3]);
    }

    public static function tryFrom(ServerRequestInterface $request): ?self
    {
        $context = $request->getAttribute(self::class);

        return $context instanceof self ? $context : null;
    }

    /**
     * Returns the `traceparent` header value to send to the services this request calls.
     */
    public function toHeader(): string
    {
        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $this->flags);
    }
}
