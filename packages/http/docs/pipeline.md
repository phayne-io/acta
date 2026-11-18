# The pipeline

The adapter is a list of PSR-15 middlewares. Each one does one thing and communicates
with the next ones through request attributes.

The library does not run them: that is the job of the PSR-15 stack of the application,
see [With Mezzio](#with-mezzio). `Acta\Http\Testing\Pipeline`, used below to show the
list at a glance, is a minimal runner for tests and examples.

```php
$api = new Pipeline(
    new OperationDispatcher($handlers),
    new CorrelationMiddleware(),
    new ProblemDetailsMiddleware($responder, $mappers, $logger, debug: false),
    new RoutingMiddleware(new Router($contract)),
    new SecurityMiddleware($contract, $authenticators),
    new RequestValidationMiddleware(new RequestValidator($contract)),
    new IdempotencyMiddleware($idempotencyStore, $responseFactory, $streamFactory),
    new ResponseValidationMiddleware(new ResponseValidator($contract), ResponseValidationMode::Fail, $logger),
);
```

## Order

| # | Middleware | Why there |
| --- | --- | --- |
| 1 | `CorrelationMiddleware` | First, so that error responses carry the trace too |
| 2 | `ProblemDetailsMiddleware` | Around everything that may throw |
| 3 | `RoutingMiddleware` | The following ones need the operation |
| 4 | `SecurityMiddleware` | Before validation: an anonymous caller learns nothing about the expected payload |
| 5 | `RequestValidationMiddleware` | Handlers only see requests that match the contract |
| 6 | `IdempotencyMiddleware` | After authentication, so that keys can be scoped to the caller |
| 7 | `ResponseValidationMiddleware` | Innermost: it checks what handlers answer, not the error responses |
| — | `OperationDispatcher` | The final handler |

## Routing

`Router` matches a method and a path against the `paths` of the contract. A concrete
path wins over a templated one, then the template with the fewest variables, then the
longest. An unknown path is a 404; a known path with another method is a 405 carrying
an `Allow` header. `HEAD` and `OPTIONS` are not implied: declare them if you serve them.

```php
new Router($contract, basePath: '/api/v1');

$match = RouteMatch::from($request);
$match->operation;        // Acta\Http\OpenApi\Operation
$match->pathParameters;   // raw strings; use the Input for typed values
```

### With a router of your own

`RouteMatch` is all the other middlewares know about routing. They read it from the
request and never call `Router`, so another router can take its place: once it has
found its route, it says which operation of the contract that route stands for.

```php
final readonly class MyRoutingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $this->router->match($request);   // yours: FastRoute, Symfony Routing, …

        $match = new RouteMatch($this->contract->operation($route->name), $route->variables);

        return $handler->handle($match->attach($request));
    }
}
```

What `Router` guarantees is then yours to keep:

- the routes are the `paths` of the contract, each named by its `operationId`, so that
  nothing is declared twice and nothing is served that the contract does not describe;
- a concrete path wins over a templated one, as OpenAPI requires. A router that takes
  the first route registered needs them registered in that order;
- an unknown path throws `HttpException::notFound()` and a known path with another
  method `HttpException::methodNotAllowed($allowed)`, for `ProblemDetailsMiddleware` to
  answer with a problem document;
- path variables are percent-decoded one by one, after matching. A router that decodes
  the whole path first sees an encoded `/` inside a variable as a separator.

## Security

The contract says which schemes and scopes each operation requires. An `Authenticator`
per scheme says whether a request has them:

```php
final readonly class BearerAuthenticator implements Authenticator
{
    public function authenticate(ServerRequestInterface $request, SecurityScheme $scheme, array $scopes): ServerRequestInterface
    {
        $principal = $this->verifier->verify($request->getHeaderLine('Authorization'))
            ?? throw HttpException::unauthorized(challenge: 'Bearer error="invalid_token"');

        $principal->hasScopes($scopes)
            || throw HttpException::forbidden(challenge: 'Bearer error="insufficient_scope"');

        return $request->withAttribute(Principal::class, $principal);
    }
}

new SecurityMiddleware($contract, ['bearer' => new BearerAuthenticator($verifier)]);
```

An operation lists alternatives; a request passes when every scheme of one alternative
accepts it. When all fail, a 403 is reported rather than a 401, since valid but
insufficient credentials say more. A scheme without authenticator is a wiring error and
answers 500: the pipeline never lets a request through because something is missing.

## Handlers

```php
#[Operation('openAccount')]
final readonly class OpenAccountHandler implements RequestHandlerInterface { /* … */ }

$handlers = new OperationHandlers($container)
    ->registerLazy(OpenAccountHandler::class, GetAccountHandler::class)   // fetched from the container on first use
    ->register(new HealthHandler())                                       // an instance
    ->on('ping', fn(ServerRequestInterface $request) => $responder->empty());   // a closure
```

An operation declared but not implemented answers 501, which lets a contract be written
ahead of its implementation. `$handlers->compare($contract)` lists operations without
handler and handlers without operation; assert in a test that both are empty.

`Responder` builds responses with the PSR-17 factories:

```php
$responder->json($data, 200, ['ETag' => '"3"']);
$responder->created('/accounts/' . $id, $data);
$responder->accepted(['version' => 3]);
$responder->empty();   // 204
```

## Tracing

`CorrelationMiddleware` continues the trace of a valid `traceparent` header, or starts
one. The `TraceContext` is a request attribute, echoed in the `traceparent` response
header and quoted as `trace_id` in problem documents and logs.

```php
$trace = TraceContext::tryFrom($request);
$outgoingRequest = $outgoingRequest->withHeader('traceparent', $trace->toHeader());
```

## Idempotency

With `IdempotencyMiddleware`, a `POST` repeated with the same `Idempotency-Key` header
is answered with the stored response of the first one, plus `Idempotency-Replayed: true`.

| Situation | Answer |
| --- | --- |
| Same key, same request, first one finished | The stored response |
| Same key, different method, path, query or body | 422 |
| Same key, first request still being handled | 409 |
| First request failed with an exception or a 5xx | Handled again |

```php
new IdempotencyMiddleware(
    new PostgresIdempotencyStore($pdo),   // or InMemoryIdempotencyStore
    $responseFactory,
    $streamFactory,
    ttl: 86400,
    methods: ['POST', 'PATCH'],
    scope: fn(ServerRequestInterface $request) => $request->getAttribute(Principal::class)->subject,
);
```

Scope the keys to the caller, otherwise two clients choosing the same key would see each
other's responses. `PostgresIdempotencyStore::install()` creates its table and `purge($now)`
removes expired keys.

The stored response and the effects of the handler are not written in one transaction.
If the process dies in between, retries are answered 409 until the key expires; they
are never handled twice.

## With Mezzio

Every class above is a plain PSR-15 middleware or handler, so they are piped like any
other in Mezzio, whose pipeline is laminas-stratigility. The router of the contract
takes the place of Mezzio's for the operations of the contract; routes that are not in
it (a health check, a webhook receiver) can still go through Mezzio's router, piped
before `RoutingMiddleware`:

```php
$app->pipe(CorrelationMiddleware::class);
$app->pipe(ProblemDetailsMiddleware::class);
$app->pipe(RoutingMiddleware::class);
$app->pipe(SecurityMiddleware::class);
$app->pipe(RequestValidationMiddleware::class);
$app->pipe(ResponseValidationMiddleware::class);
$app->pipe(OperationDispatcher::class);
```

The package ships no container configuration: its classes have plain constructors, to
be declared in the container of your choice.
