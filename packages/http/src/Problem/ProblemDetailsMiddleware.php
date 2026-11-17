<?php

declare(strict_types=1);

namespace Acta\Http\Problem;

use Acta\Http\Exception\HttpException;
use Acta\Http\Response\Responder;
use Acta\Http\Trace\TraceContext;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Turns every exception into an `application/problem+json` response (RFC 9457).
 *
 * An {@see HttpException} is sent as it is. Any other exception is first offered to the
 * {@see ExceptionMapper}s; when none knows it, the client receives a bare 500 and the
 * exception is logged. Nothing internal reaches the client unless `$debug` is on.
 */
final readonly class ProblemDetailsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<ExceptionMapper> $mappers tried in order for exceptions that are not HTTP errors
     * @param bool                  $debug   adds the class, message and trace of unexpected exceptions to
     *                                       the problem document; never enable it in production
     */
    public function __construct(
        private Responder $responder,
        private array $mappers = [],
        private LoggerInterface $logger = new NullLogger(),
        private bool $debug = false,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            $error = $this->map($exception);
            $problem = $error->problem();
            $trace = TraceContext::tryFrom($request);

            if ($trace !== null) {
                $problem = $problem->with('trace_id', $trace->traceId);
            }

            if ($this->debug && $error !== $exception) {
                $problem = $problem->with('exception', self::describe($exception));
            }

            $this->logger->log(
                $error->status >= 500 ? 'error' : 'info',
                '{method} {path} failed with status {status}: {reason}',
                [
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'status' => $error->status,
                    'reason' => $exception->getMessage(),
                    'exception' => $exception,
                    ...($trace === null ? [] : ['trace_id' => $trace->traceId]),
                ],
            );

            return $this->responder->problem($problem, $error->headers);
        }
    }

    private function map(Throwable $exception): HttpException
    {
        if ($exception instanceof HttpException) {
            return $exception;
        }

        foreach ($this->mappers as $mapper) {
            $mapped = $mapper->map($exception);

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return new HttpException(500, previous: $exception);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Throwable $exception): array
    {
        $previous = $exception->getPrevious();

        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
            ...($previous === null ? [] : ['previous' => self::describe($previous)]),
        ];
    }
}
