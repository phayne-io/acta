<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\EventSourcing\MetadataFactory;
use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Handler\OperationHandlers;
use Acta\Http\Idempotency\IdempotencyMiddleware;
use Acta\Http\Idempotency\IdempotencyStore;
use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\ContractLoader;
use Acta\Http\Problem\ExceptionMapper;
use Acta\Http\Problem\ProblemDetailsMiddleware;
use Acta\Http\Response\Responder;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Security\Authenticator;
use Acta\Http\Security\SecurityMiddleware;
use Acta\Http\Validation\RequestValidationMiddleware;
use Acta\Http\Validation\RequestValidator;
use Acta\Http\Validation\ResponseValidationMiddleware;
use Acta\Http\Validation\ResponseValidationMode;
use Acta\Http\Validation\ResponseValidator;
use Acta\Oidc\Token\Principal;
use App\Platform\Settings;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the HTTP primary adapter around the OpenAPI contract.
 *
 * The contract is the single source of truth: routes, security requirements and
 * validation all come from `openapi/openapi.yaml`. Contexts only list the request
 * handlers of their operations under `acta.operations`.
 */
final class HttpFactories
{
    public static function contract(ContainerInterface $container): Contract
    {
        $settings = Settings::of($container);
        $source = $settings->path('acta.http.contract');
        $cache = $settings->path('acta.http.contract_cache');

        // In debug mode the compiled contract is rebuilt whenever its source changes.
        return $cache === ''
            ? ContractLoader::load($source)
            : ContractLoader::cached($source, $cache, $settings->bool('debug'));
    }

    public static function router(ContainerInterface $container): Router
    {
        return new Router(Resolve::from($container, Contract::class), Settings::of($container)->string('acta.http.base_path'));
    }

    public static function routing(ContainerInterface $container): RoutingMiddleware
    {
        return new RoutingMiddleware(Resolve::from($container, Router::class));
    }

    public static function responder(ContainerInterface $container): Responder
    {
        return new Responder(
            Resolve::from($container, ResponseFactoryInterface::class),
            Resolve::from($container, StreamFactoryInterface::class),
        );
    }

    public static function problemDetails(ContainerInterface $container): ProblemDetailsMiddleware
    {
        $settings = Settings::of($container);

        return new ProblemDetailsMiddleware(
            Resolve::from($container, Responder::class),
            Resolve::all($container, array_unique($settings->strings('acta.exception_mappers')), ExceptionMapper::class),
            Resolve::from($container, LoggerInterface::class),
            $settings->bool('debug'),
        );
    }

    public static function security(ContainerInterface $container): SecurityMiddleware
    {
        $authenticators = [];

        // Keyed by the name of a security scheme of the contract.
        foreach (Settings::of($container)->strings('acta.http.authenticators') as $scheme => $authenticator) {
            $authenticators[(string) $scheme] = Resolve::named($container, $authenticator, Authenticator::class);
        }

        return new SecurityMiddleware(Resolve::from($container, Contract::class), $authenticators);
    }

    public static function requestValidation(ContainerInterface $container): RequestValidationMiddleware
    {
        return new RequestValidationMiddleware(new RequestValidator(Resolve::from($container, Contract::class)));
    }

    public static function responseValidation(ContainerInterface $container): ResponseValidationMiddleware
    {
        // A response that breaks the contract is a defect: it fails where developers see
        // it and is only logged where clients would pay for it.
        $mode = Settings::of($container)->string('acta.http.response_validation', 'log') === 'fail'
            ? ResponseValidationMode::Fail
            : ResponseValidationMode::Log;

        return new ResponseValidationMiddleware(
            new ResponseValidator(Resolve::from($container, Contract::class)),
            $mode,
            Resolve::from($container, LoggerInterface::class),
        );
    }

    public static function idempotency(ContainerInterface $container): IdempotencyMiddleware
    {
        return new IdempotencyMiddleware(
            Resolve::from($container, IdempotencyStore::class),
            Resolve::from($container, ResponseFactoryInterface::class),
            Resolve::from($container, StreamFactoryInterface::class),
            Resolve::from($container, ClockInterface::class),
            Settings::of($container)->int('acta.http.idempotency_ttl', 86400),
            // Two callers may pick the same key: each one gets its own.
            scope: static fn(ServerRequestInterface $request): string => Principal::tryFrom($request)->subject ?? '',
        );
    }

    public static function operationHandlers(ContainerInterface $container): OperationHandlers
    {
        return new OperationHandlers($container)->registerLazy(
            ...Settings::of($container)->implementations('acta.operations', RequestHandlerInterface::class),
        );
    }

    public static function dispatcher(ContainerInterface $container): OperationDispatcher
    {
        return new OperationDispatcher(Resolve::from($container, OperationHandlers::class));
    }

    public static function messages(): MessageFactory
    {
        return new MessageFactory();
    }

    /**
     * What a request contributes to the metadata of the messages it causes: its trace,
     * which `acta/http` adds by itself, and the authenticated caller.
     */
    public static function metadata(): MetadataFactory
    {
        return new MetadataFactory([
            MessagingFactories::ACTOR => static fn(ServerRequestInterface $request): ?string => Principal::tryFrom($request)?->subject,
        ]);
    }
}
