# Acta HTTP

A contract-first HTTP adapter for PHP 8.5+. An OpenAPI 3.1 description drives the
routing, the security requirements, the validation of requests and responses, and the
errors follow RFC 9457. Everything is a PSR-15 middleware working on PSR-7 messages
built with PSR-17 factories.

The contract is written by hand and is the source of truth: nothing it says is declared
a second time in PHP.

- **Routing** from the `paths` of the contract. No route table to maintain.
- **Security** requirements enforced per operation, with authentication plugged in
  through a port.
- **Request validation**: path, query, header and cookie parameters decoded with their
  OpenAPI serialization style and typed from their schema; JSON and form bodies checked
  against JSON Schema 2020-12.
- **Response validation**, failing in development and logging in production.
- **Errors** as `application/problem+json`, with every violation listed.
- **Tracing** with W3C Trace Context, and **idempotent retries** with `Idempotency-Key`.
- **Handlers** attached to operations by `operationId`, resolved lazily from a PSR-11
  container.
- **A bridge to [`acta/event-sourcing`](../event-sourcing)**, optional, to turn validated
  input into commands and queries.

## Requirements

- PHP 8.5 or later
- `opis/json-schema`, and the PSR interfaces for HTTP messages, factories, handlers and
  middlewares, containers, logging and clocks
- Any PSR-7 and PSR-17 implementation, such as `laminas/laminas-diactoros`
- To read a YAML contract: `symfony/yaml` or `ext-yaml`. Not needed in production once
  the contract is compiled.

## Installation

```bash
composer require acta/http
```

## Quick start

The snippets below follow [`examples/quickstart.php`](examples/quickstart.php), which
runs as is against [`examples/openapi.yaml`](examples/openapi.yaml).

### 1. Describe the operation

```yaml
paths:
  /accounts/{accountId}/deposits:
    post:
      operationId: depositMoney
      security:
        - bearer: ['accounts:write']
      parameters:
        - $ref: '#/components/parameters/AccountId'
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/Money' }
      responses:
        '200':
          description: The account after the deposit.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/Account' }
        default: { $ref: '#/components/responses/Problem' }
```

### 2. Implement it

A handler is a PSR-15 request handler named after the `operationId`. It receives a
request that already matches the contract and reads its `Input`: no parsing, no
validation, only the translation to a command.

```php
#[Operation('depositMoney')]
final readonly class DepositMoneyHandler implements RequestHandlerInterface
{
    public function __construct(private CommandBus $commands, private AccountView $view) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = Input::from($request);
        $id = AccountId::fromString($input->path['accountId']);
        $money = new Money($input->body['amount'], new Currency($input->body['currency']));

        $this->commands->dispatch(new DepositMoney($id, $money));

        return $this->view->respond($id);
    }
}
```

### 3. Assemble the pipeline

```php
$contract = ContractLoader::cached('openapi/openapi.yaml', 'var/cache/contract.php');
$responder = new Responder($responseFactory, $streamFactory);
$handlers = new OperationHandlers($container)->registerLazy(DepositMoneyHandler::class /* , ... */);

$api = new Pipeline(
    new OperationDispatcher($handlers),
    new CorrelationMiddleware(),                                         // a trace for every request
    new ProblemDetailsMiddleware($responder, $mappers, $logger),         // exceptions as problem+json
    new RoutingMiddleware(new Router($contract)),                        // the paths of the contract
    new SecurityMiddleware($contract, ['bearer' => $authenticator]),     // its security requirements
    new RequestValidationMiddleware(new RequestValidator($contract)),    // its parameters and bodies
    new ResponseValidationMiddleware(new ResponseValidator($contract)),  // and its responses
);

$response = $api->handle($request);
```

`Pipeline` is a twenty-line PSR-15 runner. With Mezzio or another PSR-15 stack, pipe the
same middlewares there instead; see [the pipeline guide](docs/pipeline.md).

### 4. What clients get

A request that breaks the contract never reaches the application:

```http
POST /accounts
{"owner": "", "currency": "GBP"}

HTTP/1.1 422 Unprocessable Content
Content-Type: application/problem+json

{
  "type": "about:blank",
  "title": "Unprocessable Content",
  "status": 422,
  "detail": "The request does not match the contract: 2 errors.",
  "errors": [
    {"in": "body", "pointer": "/owner", "detail": "Minimum string length is 1, found 0"},
    {"in": "body", "pointer": "/currency", "detail": "The data should match one item from enum"}
  ],
  "trace_id": "e71cd163aa08bcfaf48e44ef23069e69"
}
```

Neither does an unknown path (404), an undeclared method (405, with `Allow`), a missing
or insufficient token (401, 403), an undeclared media type (415) or malformed JSON (400).

## Keeping code and contract in agreement

```php
final class ContractTest extends TestCase
{
    public function testTheContractIsValidAndImplemented(): void
    {
        ContractAssertions::assertContractIsValid($this->contract);
        ContractAssertions::assertContractIsImplemented($this->contract, $this->handlers);
    }

    public function testOpeningAnAccount(): void
    {
        $response = $this->api->handle($request);

        ContractAssertions::assertResponseMatchesContract($this->contract, 'openAccount', $response);
    }
}
```

## Documentation

| Guide | What it covers |
| --- | --- |
| [The contract](docs/contract.md) | Loading, compiling and linting; what is read from the description; what is not supported |
| [The pipeline](docs/pipeline.md) | Each middleware, their order, handlers, tracing, idempotency, use with Mezzio |
| [Validation](docs/validation.md) | Parameter styles and typing, bodies, the `Input`, responses, custom formats |
| [Errors](docs/errors.md) | Problem documents, `HttpException`, mapping the exceptions of the application |
| [With acta/event-sourcing](docs/event-sourcing.md) | Commands from input, metadata, `If-Match`, error mapping |

Every public class and method is documented in the source as well.

## Development

```bash
docker compose up -d      # PostgreSQL for the integration tests
composer install
composer check            # coding standard, static analysis, tests
composer test:unit        # tests that do not need PostgreSQL
```

Inside the Acta monorepo, `acta/event-sourcing` is installed from the sibling directory.
The PostgreSQL tests read their connection from the `ACTA_TEST_POSTGRES_*` environment
variables, see [`phpunit.xml.dist`](phpunit.xml.dist), and are skipped when the server
cannot be reached.

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

Released under the [MIT License](LICENSE).
