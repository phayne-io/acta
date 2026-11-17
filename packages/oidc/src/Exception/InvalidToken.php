<?php

declare(strict_types=1);

namespace Acta\Oidc\Exception;

use Acta\Oidc\Token\TokenError;
use RuntimeException;

/**
 * A token was refused.
 *
 * The message is written for the party presenting the token and reveals nothing about
 * keys or configuration: it can be sent back as an `error_description`.
 */
final class InvalidToken extends RuntimeException implements OidcException
{
    public function __construct(
        public readonly TokenError $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
