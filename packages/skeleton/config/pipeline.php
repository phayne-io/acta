<?php

declare(strict_types=1);

use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Idempotency\IdempotencyMiddleware;
use Acta\Http\Problem\ProblemDetailsMiddleware;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Security\SecurityMiddleware;
use Acta\Http\Trace\CorrelationMiddleware;
use Acta\Http\Validation\RequestValidationMiddleware;
use Acta\Http\Validation\ResponseValidationMiddleware;
use Mezzio\Application;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;

/*
 * The PSR-15 pipeline every request goes through, outermost first.
 */
return static function (Application $app): void {
    // A trace for every request (W3C Trace Context), carried to the events it causes.
    $app->pipe(CorrelationMiddleware::class);

    // Every failure below becomes an RFC 9457 problem document.
    $app->pipe(ProblemDetailsMiddleware::class);

    // The few routes that are not part of the contract: see config/routes.php.
    $app->pipe(RouteMiddleware::class);
    $app->pipe(DispatchMiddleware::class);

    // Everything else is an operation of openapi/openapi.yaml, or a 404.
    $app->pipe(RoutingMiddleware::class);
    $app->pipe(SecurityMiddleware::class);            // its security requirements: Bearer JWT and scopes
    $app->pipe(RequestValidationMiddleware::class);   // its parameters and its request body
    $app->pipe(IdempotencyMiddleware::class);         // Idempotency-Key: a retried POST is handled once
    $app->pipe(ResponseValidationMiddleware::class);  // its responses
    $app->pipe(OperationDispatcher::class);           // the request handler of the operation
};
