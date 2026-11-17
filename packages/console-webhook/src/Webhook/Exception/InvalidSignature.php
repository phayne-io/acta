<?php

declare(strict_types=1);

namespace Acta\Webhook\Exception;

use RuntimeException;

/**
 * A webhook cannot be attributed to its sender: its signature is missing or wrong, or
 * it was signed too long ago.
 *
 * The message says which, without revealing anything about the secret.
 */
final class InvalidSignature extends RuntimeException implements WebhookException {}
