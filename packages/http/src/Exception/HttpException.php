<?php

declare(strict_types=1);

namespace Acta\Http\Exception;

use Acta\Http\Problem\ProblemDetails;
use RuntimeException;
use Throwable;

/**
 * An error with a meaning for the HTTP client: a status, headers and a problem document.
 *
 * Throw it from a middleware or a handler; {@see \Acta\Http\Problem\ProblemDetailsMiddleware}
 * turns it into an `application/problem+json` response. The detail is sent to the
 * client, so it must not reveal anything internal.
 */
class HttpException extends RuntimeException implements ActaHttpException
{
    private const array TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        410 => 'Gone',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * @param int                   $status     HTTP status code, from 400 to 599
     * @param string                $detail     explanation specific to this occurrence, shown to the client
     * @param array<string, string> $headers    headers to add to the response
     * @param string                $type       URI identifying the problem type
     * @param string|null           $title      summary of the problem type; defaults to the reason phrase
     * @param array<string, mixed>  $extensions additional members of the problem document
     */
    public function __construct(
        public readonly int $status,
        string $detail = '',
        public readonly array $headers = [],
        public readonly string $type = ProblemDetails::BLANK,
        public readonly ?string $title = null,
        public readonly array $extensions = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail !== '' ? $detail : self::titleOf($status), $status, $previous);
    }

    /**
     * Returns the reason phrase of a status code.
     */
    public static function titleOf(int $status): string
    {
        return self::TITLES[$status] ?? ($status >= 500 ? 'Server Error' : 'Client Error');
    }

    public static function badRequest(string $detail = '', ?Throwable $previous = null): self
    {
        return new self(400, $detail, previous: $previous);
    }

    /**
     * @param string $challenge value of the `WWW-Authenticate` header, as required by RFC 9110
     */
    public static function unauthorized(string $detail = '', string $challenge = 'Bearer'): self
    {
        return new self(401, $detail, ['WWW-Authenticate' => $challenge]);
    }

    /**
     * @param string|null $challenge optional `WWW-Authenticate` header, such as the `insufficient_scope` error of RFC 6750
     */
    public static function forbidden(string $detail = '', ?string $challenge = null): self
    {
        return new self(403, $detail, $challenge === null ? [] : ['WWW-Authenticate' => $challenge]);
    }

    public static function notFound(string $detail = ''): self
    {
        return new self(404, $detail);
    }

    /**
     * @param list<string> $allowed methods the resource supports
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, headers: ['Allow' => implode(', ', $allowed)]);
    }

    public static function conflict(string $detail = '', ?Throwable $previous = null): self
    {
        return new self(409, $detail, previous: $previous);
    }

    public static function preconditionFailed(string $detail = '', ?Throwable $previous = null): self
    {
        return new self(412, $detail, previous: $previous);
    }

    public static function unsupportedMediaType(string $detail = ''): self
    {
        return new self(415, $detail);
    }

    public static function unprocessable(string $detail = ''): self
    {
        return new self(422, $detail);
    }

    public static function notImplemented(string $detail = ''): self
    {
        return new self(501, $detail);
    }

    /**
     * Returns the problem document describing this error.
     */
    public function problem(): ProblemDetails
    {
        return new ProblemDetails(
            $this->status,
            $this->title ?? self::titleOf($this->status),
            $this->getMessage() === self::titleOf($this->status) ? null : $this->getMessage(),
            $this->type,
            extensions: $this->extensions,
        );
    }
}
