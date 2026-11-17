<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Security scheme declared in the components of the contract.
 */
final readonly class SecurityScheme
{
    /**
     * @param string               $name       key of the scheme in `components.securitySchemes`
     * @param string               $type       `http`, `apiKey`, `oauth2`, `openIdConnect` or `mutualTLS`
     * @param string|null          $scheme     HTTP authentication scheme in lower case, such as `bearer`, for the `http` type
     * @param array<string, mixed> $definition the whole Security Scheme Object
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $scheme,
        public array $definition,
    ) {}
}
