<?php

declare(strict_types=1);

namespace Acta\Oidc\Exception;

use LogicException;

/**
 * The package is used in a way that cannot work: an insecure issuer, an endpoint the
 * provider does not have, an algorithm that is not available. To fix before deploying.
 */
final class ConfigurationError extends LogicException implements OidcException {}
