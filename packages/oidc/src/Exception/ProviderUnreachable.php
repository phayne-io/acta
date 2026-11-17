<?php

declare(strict_types=1);

namespace Acta\Oidc\Exception;

use RuntimeException;

/**
 * The identity provider could not be reached, or answered something unusable.
 *
 * Nothing can be concluded about a token or a login when this happens: treat it as a
 * temporary failure, not as a refusal.
 */
class ProviderUnreachable extends RuntimeException implements OidcException {}
