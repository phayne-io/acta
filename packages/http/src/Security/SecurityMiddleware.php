<?php

declare(strict_types=1);

namespace Acta\Http\Security;

use Acta\Http\Exception\ContractError;
use Acta\Http\Exception\HttpException;
use Acta\Http\OpenApi\Contract;
use Acta\Http\Routing\RouteMatch;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces the `security` requirements of the contract.
 *
 * An operation lists alternatives; a request passes when it satisfies every scheme of
 * one of them. An operation without requirement is public. A scheme without
 * authenticator is a wiring error and fails closed, as a server error.
 *
 * Place it after {@see \Acta\Http\Routing\RoutingMiddleware} and before validation, so
 * that an anonymous caller learns nothing about the expected payload.
 */
final readonly class SecurityMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, Authenticator> $authenticators keyed by the name of the security scheme they handle
     */
    public function __construct(
        private Contract $contract,
        private array $authenticators,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $operation = RouteMatch::from($request)->operation;

        if ($operation->security === []) {
            return $handler->handle($request);
        }

        $failure = null;

        foreach ($operation->security as $alternative) {
            try {
                $authenticated = $request;

                foreach ($alternative as $name => $scopes) {
                    $name = (string) $name;
                    $authenticator = $this->authenticators[$name] ?? throw new ContractError(sprintf(
                        'Operation "%s" requires the security scheme "%s", which has no authenticator.',
                        $operation->id,
                        $name,
                    ));
                    $authenticated = $authenticator->authenticate($authenticated, $this->contract->securitySchemes[$name], $scopes);
                }
            } catch (HttpException $exception) {
                if ($exception->status !== 401 && $exception->status !== 403) {
                    throw $exception;
                }

                // Valid but insufficient credentials say more than missing ones: report them first.
                if ($failure === null || ($exception->status === 403 && $failure->status === 401)) {
                    $failure = $exception;
                }

                continue;
            }

            return $handler->handle($authenticated);
        }

        throw $failure;
    }
}
