<?php

declare(strict_types=1);

namespace Acta\Oidc\Exception;

use RuntimeException;

/**
 * No usable token is stored: nobody logged in yet, or the session can no longer be refreshed.
 */
final class NoToken extends RuntimeException implements OidcException {}
