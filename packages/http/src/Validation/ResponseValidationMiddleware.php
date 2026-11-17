<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use Acta\Http\Exception\ResponseContractViolation;
use Acta\Http\Routing\RouteMatch;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Checks what the handlers answer against the contract.
 *
 * In {@see ResponseValidationMode::Fail} a response the contract does not describe
 * becomes a server error, which keeps the contract honest during development and in
 * tests. In {@see ResponseValidationMode::Log} the response is sent and a warning is
 * logged. Error responses built by {@see \Acta\Http\Problem\ProblemDetailsMiddleware}
 * do not go through this middleware.
 */
final readonly class ResponseValidationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseValidator $validator,
        private ResponseValidationMode $mode = ResponseValidationMode::Fail,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $operation = RouteMatch::tryFrom($request)?->operation;

        if ($operation === null) {
            return $response;
        }

        $violations = $this->validator->validate($response, $operation);

        if ($violations === []) {
            return $response;
        }

        $failure = new ResponseContractViolation($operation->id, $violations);

        if ($this->mode === ResponseValidationMode::Fail) {
            throw $failure;
        }

        $this->logger->warning('The response of operation {operation} does not match the contract.', [
            'operation' => $operation->id,
            'status' => $response->getStatusCode(),
            'violations' => array_map(static fn(Violation $violation): array => $violation->toArray(), $violations),
            'exception' => $failure,
        ]);

        return $response;
    }
}
