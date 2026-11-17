# The contract

The OpenAPI 3.1 description of the API is written by hand and read at runtime. It is
the one place where routes, parameters, bodies, responses and security requirements are
declared.

## Loading

```php
$contract = ContractLoader::load('openapi/openapi.yaml');   // .json, .yaml, .yml or a compiled .php
$contract = ContractLoader::fromJson($json);
$contract = ContractLoader::fromYaml($yaml);
```

YAML needs `symfony/yaml` or the `yaml` extension. The description must be a single
document: references to other files are refused, so bundle a multi-file contract first
(Redocly CLI and similar tools do it).

## Compiling

Parsing YAML on every request would be wasteful. `cached()` writes the description once
as a PHP file, which opcache keeps in memory:

```php
$contract = ContractLoader::cached(
    'openapi/openapi.yaml',
    'var/cache/contract.php',
    watch: $isDevelopment,   // recompile when the source is newer
);
```

In production, build the cache at deployment with `ContractLoader::compile()` and leave
`watch` off: neither the YAML parser nor the source file is then needed at runtime.

## What is read

```php
$operation = $contract->operation('depositMoney');

$operation->method;                  // 'POST'
$operation->path;                    // '/accounts/{accountId}/deposits'
$operation->parameters;              // list<Parameter>, those of the path item included
$operation->requestBody?->content;   // media type => MediaType
$operation->responseFor(404);        // exact code, then '4XX', then 'default'
$operation->security;                // [['bearer' => ['accounts:write']]]
$operation->extensions;              // the x-… members, for your own conventions
```

- Every operation needs an `operationId`, unique in the document: it is how handlers
  are attached.
- An operation without `security` inherits the top-level one; `security: []` makes it
  public.
- `$ref`s to parameters, request bodies, responses, headers and path items are followed.
  Schemas are not copied: they are validated in place, so references between schemas,
  recursive ones included, simply work.
- `webhooks` are read the same way and kept apart, in `$contract->webhooks`. They
  describe requests the API sends; validate their payloads with `SchemaValidator`.

`$contract->document` is the whole description as decoded JSON, should you need a part
this model does not expose.

## Linting

```php
foreach (ContractLinter::lintFile('openapi/openapi.yaml') as $issue) {
    echo $issue->describe(), PHP_EOL;   // #/paths/~1accounts/post/responses: Operation "openAccount" declares no response.
}
```

The linter checks what this package relies on: the description loads, every `$ref`
leads somewhere, the schemas used by operations can be read by the validator, operations
declare described responses, `http` security schemes name their scheme. It is not a
complete OpenAPI validator; run one alongside if you want style rules.

In a test suite, `ContractAssertions::assertContractIsValid($contract)` fails with the
list of issues.

## What is not supported

| Feature | Behaviour |
| --- | --- |
| OpenAPI 3.0 and 2.0 | Refused: schemas differ from JSON Schema 2020-12 |
| References to other files or URLs | Refused; bundle the contract |
| `matrix` and `label` path styles | Refused when loading |
| `servers` | Ignored; give the prefix to `new Router($contract, '/api/v1')` |
| `callbacks`, `links`, `examples` | Ignored |
| `readOnly` and `writeOnly` | Treated as annotations: not enforced per direction |
| XML bodies | Passed to the handler as strings, validated as strings |
