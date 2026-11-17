# Errors

Every error leaves the API as a problem document (RFC 9457), with the media type
`application/problem+json`:

```json
{
  "type": "about:blank",
  "title": "Precondition Failed",
  "status": 412,
  "detail": "The resource is at version 2, not at version 1. Read it again, then retry.",
  "trace_id": "4c14f8d2d27fd152f11c9610250d0a6f"
}
```

`ProblemDetailsMiddleware` builds it from whatever was thrown inside the pipeline.

## HTTP exceptions

Throw an `HttpException` from a middleware or a handler when the error has a meaning for
the client:

```php
throw HttpException::notFound();
throw HttpException::conflict('This account is already closed.');
throw HttpException::unauthorized(challenge: 'Bearer error="invalid_token"');
throw HttpException::methodNotAllowed(['GET', 'POST']);

throw new HttpException(
    402,
    'Top up the account first.',
    headers: ['Retry-After' => '3600'],
    type: 'https://api.example.com/problems/payment-required',
    title: 'Payment required',
    extensions: ['price' => '9.99'],
);
```

The detail is sent to the client: say what the client can act on, nothing internal.

What the pipeline itself throws:

| Status | When |
| --- | --- |
| 400 | Malformed JSON body, unreadable `Idempotency-Key` or `If-Match` |
| 401, 403 | Thrown by your authenticators |
| 404, 405 | No such path; path without that method |
| 409 | An idempotency key still being handled |
| 415 | Media type the operation does not declare |
| 422 | Request that breaks the contract (`ContractViolation`); idempotency key reused for another request |
| 501 | Operation declared but not implemented |

A `ContractViolation` lists what is wrong in an `errors` member. Each entry says where
the value is (`in`: `path`, `query`, `header`, `cookie` or `body`), the `name` of the
parameter, the JSON `pointer` inside the value, and a `detail`.

## Exceptions of the application

The domain and the application layer throw their own exceptions and know nothing about
HTTP. An `ExceptionMapper` gives them a status at the edge:

```php
final readonly class BusinessRuleMapper implements ExceptionMapper
{
    public function map(Throwable $exception): ?HttpException
    {
        return match (true) {
            $exception instanceof InsufficientFunds => new HttpException(
                422,
                $exception->getMessage(),
                type: 'https://api.example.com/problems/insufficient-funds',
                title: 'Insufficient funds',
                previous: $exception,
            ),
            $exception instanceof DomainException => HttpException::unprocessable($exception->getMessage()),
            default => null,   // not mine: try the next mapper
        };
    }
}

new ProblemDetailsMiddleware($responder, [new EventSourcingExceptionMapper(), new BusinessRuleMapper()], $logger);
```

Mappers are tried in order. An exception none of them knows becomes a bare 500:

```json
{"type": "about:blank", "title": "Internal Server Error", "status": 500, "trace_id": "…"}
```

Its message never reaches the client. With `debug: true` the document gains an
`exception` member holding the class, the message and the trace; keep it for
development.

## Logging

The middleware logs every error through PSR-3: server errors at the `error` level,
client errors at `info`. The context holds the method, the path, the status, the trace
identifier and the original exception, mapped or not.

## Describing errors in the contract

Declare the problem document once and reference it as the `default` response of your
operations, so that clients generated from the contract know its shape:

```yaml
components:
  responses:
    Problem:
      description: The request failed.
      content:
        application/problem+json:
          schema:
            type: object
            required: [type, title, status]
            properties:
              type: { type: string }
              title: { type: string }
              status: { type: integer }
              detail: { type: string }
```
