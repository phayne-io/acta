# Validation

## Requests

`RequestValidationMiddleware` checks a request against its operation and stores the
decoded result as an `Input`:

```php
$input = Input::from($request);

$input->path['accountId'];   // '018f4e3a-…'
$input->query['limit'];      // 20, an int, the default of the schema
$input->headers['If-Match'];
$input->cookies;
$input->body;                // arrays for JSON and forms, a string otherwise, null when absent
$input->fields();            // the body when it is an object, [] otherwise
$input->contentType;         // 'application/json'
```

Only declared parameters appear in the input; undeclared query parameters are ignored.
A parameter that was not sent is absent, unless its schema has a `default`.

Every violation is collected before answering, so a client can fix them all at once.
The response is a 422 by default; pass `violationStatus: 400` to `RequestValidator` if
your API guidelines prefer it.

### Parameters

Parameters are decoded with the serialization style the contract gives them:

| In | Style | Example for `ids` = `[1, 2]` or `point` = `{x: 1, y: 2}` |
| --- | --- | --- |
| query | `form`, exploded (default) | `?ids=1&ids=2` · `?x=1&y=2` |
| query | `form`, not exploded | `?ids=1,2` · `?point=x,1,y,2` |
| query | `spaceDelimited`, `pipeDelimited` | `?ids=1%202` · `?ids=1\|2` |
| query | `deepObject` | `?point[x]=1&point[y]=2` |
| path, header | `simple` | `1,2` · `x,1,y,2`, or `x=1,y=2` when exploded |
| cookie | `form` | `ids=1,2` |
| any | `content` with `application/json` | `?point={"x":1,"y":2}`, URL-encoded |

The PHP notation `?ids[]=1&ids[]=2` is accepted as well for arrays.

Everything in a URL or a header is a string. Before validating, strings are converted to
the type their schema asks for: `integer`, `number`, `boolean`, and `null` for an empty
value. A schema that accepts strings, or does not say, leaves the value alone. The
conversion follows local `$ref`s and looks into `allOf`, `anyOf` and `oneOf`. A string
that does not look like the expected type is left as it is, and validation reports it.

As OpenAPI requires, header parameters named `Accept`, `Content-Type` and
`Authorization` are ignored.

### Bodies

| Media type | Decoded as | Validated |
| --- | --- | --- |
| `application/json`, `…+json` | arrays | yes |
| `application/x-www-form-urlencoded` | array of fields, typed from the schema | yes |
| `multipart/form-data` | the parsed body of the request, typed from the schema | yes, files excluded |
| anything else | string | against the schema, as a string |

The media type of the request must be one the operation declares, ranges such as
`text/*` included; otherwise the answer is a 415. Malformed JSON is a 400. A body sent
to an operation that declares none is ignored.

## Responses

`ResponseValidationMiddleware` checks what handlers answer: the status must be declared
(exactly, by range such as `2XX`, or by `default`), required headers present, the media
type declared, and the body valid against its schema.

```php
new ResponseValidationMiddleware($validator, ResponseValidationMode::Fail);          // development, tests
new ResponseValidationMiddleware($validator, ResponseValidationMode::Log, $logger);  // production
```

In `Fail` mode an undeclared response becomes a 500, which keeps the contract honest
while developing. In `Log` mode the response is sent and a warning is logged with the
violations. Error responses built by `ProblemDetailsMiddleware` do not go through this
middleware; check them in tests with `ContractAssertions::assertResponseMatchesContract()`.

## Schemas

OpenAPI 3.1 schemas are JSON Schema 2020-12, validated by `opis/json-schema` with the
whole contract registered as one document. Formats such as `uuid`, `date-time` and
`email` are enforced.

To add formats or change limits, configure the validator yourself and share it:

```php
$opis = new Opis\JsonSchema\Validator();
$opis->parser()->getFormatResolver()?->registerCallable('string', 'iban', fn(string $value): bool => Iban::isValid($value));

$schemas = new SchemaValidator($contract, $opis, maxErrors: 50);

new RequestValidator($contract, $schemas);
new ResponseValidator($contract, $schemas);
```

`SchemaValidator` can also validate any value against any schema of the contract, for
instance the payload of an outgoing webhook:

```php
$errors = $schemas->validate(
    SchemaValidator::toJson($payload),
    '/webhooks/accountOpened/post/requestBody/content/application~1json/schema',
);   // ['/owner' => ['The data (integer) must match the type: string']]
```
