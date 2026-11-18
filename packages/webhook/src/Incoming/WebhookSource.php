<?php

declare(strict_types=1);

namespace Acta\Webhook\Incoming;

use Acta\Webhook\Signature\SignatureScheme;

/**
 * A sender of webhooks the application accepts: how it signs, and what its webhooks mean.
 */
final readonly class WebhookSource
{
    /**
     * @param string $name name of the sender, the last segment of its URL: `/webhooks/{name}`
     */
    public function __construct(
        public string $name,
        public SignatureScheme $signature,
        public WebhookTranslator $translator,
    ) {}
}
