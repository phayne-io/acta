<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Operation of the contract: one method on one path, or one webhook.
 */
final readonly class Operation
{
    /**
     * @param string                            $id         the `operationId`, unique in the contract
     * @param string                            $method     HTTP method in upper case
     * @param string                            $path       path template such as `/accounts/{accountId}`, or the name of the webhook
     * @param list<Parameter>                   $parameters parameters of the operation, those of its path item included
     * @param array<int|string, Response>       $responses  declared responses, keyed by status code, range or `default`
     * @param list<array<string, list<string>>> $security   alternatives, each mapping the schemes it needs to their scopes; empty when the operation is public
     * @param list<string>                      $tags
     * @param array<string, mixed>              $extensions the `x-` members of the Operation Object
     * @param string                            $pointer    JSON pointer to the operation in the contract
     * @param bool                              $isWebhook  whether the operation describes a request the API sends
     */
    public function __construct(
        public string $id,
        public string $method,
        public string $path,
        public array $parameters,
        public ?RequestBody $requestBody,
        public array $responses,
        public array $security,
        public array $tags,
        public ?string $summary,
        public bool $deprecated,
        public array $extensions,
        public string $pointer,
        public bool $isWebhook = false,
    ) {}

    /**
     * Returns the declared response for a status code: the exact code first, then its
     * range (`2XX`), then `default`.
     */
    public function responseFor(int $status): ?Response
    {
        return $this->responses[(string) $status]
            ?? $this->responses[intdiv($status, 100) . 'XX']
            ?? $this->responses['default']
            ?? null;
    }

    /**
     * @return list<Parameter>
     */
    public function parametersIn(ParameterLocation $location): array
    {
        return array_values(array_filter(
            $this->parameters,
            static fn(Parameter $parameter): bool => $parameter->in === $location,
        ));
    }
}
