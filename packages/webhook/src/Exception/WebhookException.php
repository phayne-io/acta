<?php

declare(strict_types=1);

namespace Acta\Webhook\Exception;

use Throwable;

/**
 * Marker implemented by every exception thrown by the webhook adapters.
 */
interface WebhookException extends Throwable {}
