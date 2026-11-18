<?php

declare(strict_types=1);

namespace Acta\Webhook\Exception;

use RuntimeException;

/**
 * A translator refuses a webhook that is authentic but makes no sense to the
 * application: sending it again would not help.
 *
 * The sender is answered 422 and the delivery is not processed again. For a failure
 * that a retry may fix, let any other exception through instead.
 */
final class UnprocessableWebhook extends RuntimeException implements WebhookException {}
