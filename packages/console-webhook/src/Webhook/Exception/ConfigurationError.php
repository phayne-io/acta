<?php

declare(strict_types=1);

namespace Acta\Webhook\Exception;

use LogicException;

/**
 * The webhook adapters are wired in a way that cannot work. To fix before deploying.
 */
final class ConfigurationError extends LogicException implements WebhookException {}
