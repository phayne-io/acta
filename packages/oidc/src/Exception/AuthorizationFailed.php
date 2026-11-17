<?php

declare(strict_types=1);

namespace Acta\Oidc\Exception;

use RuntimeException;

/**
 * A login did not complete: the user refused, the request expired, or the callback is
 * not the answer to the request that was made.
 */
final class AuthorizationFailed extends RuntimeException implements OidcException
{
    /**
     * @param string $error error code: one of OAuth 2.0 (`access_denied`, `expired_token`…) or
     *                      `invalid_callback` when the callback itself cannot be trusted
     */
    public function __construct(
        public readonly string $error,
        ?string $description = null,
    ) {
        parent::__construct($description === null ? $error : sprintf('%s: %s', $error, $description));
    }
}
