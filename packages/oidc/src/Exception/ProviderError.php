<?php

declare(strict_types=1);

namespace Acta\Oidc\Exception;

use RuntimeException;

/**
 * The identity provider answered with an OAuth 2.0 error (RFC 6749, section 5.2).
 */
final class ProviderError extends RuntimeException implements OidcException
{
    /**
     * @param string      $error       error code, such as `invalid_grant` or `invalid_client`
     * @param string|null $description human-readable explanation given by the provider
     * @param string|null $uri         page documenting the error, when the provider gives one
     * @param int         $status      HTTP status of the response
     */
    public function __construct(
        public readonly string $error,
        public readonly ?string $description = null,
        public readonly ?string $uri = null,
        public readonly int $status = 400,
    ) {
        parent::__construct($description === null ? $error : sprintf('%s: %s', $error, $description));
    }
}
