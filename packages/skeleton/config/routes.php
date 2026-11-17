<?php

declare(strict_types=1);

use Acta\Webhook\Incoming\WebhookReceiver;
use App\Platform\Http\ContractAction;
use App\Platform\Http\HealthAction;
use Mezzio\Application;

/*
 * Routes that are not operations of the contract.
 *
 * The API itself is not routed here: its paths are those of openapi/openapi.yaml, and
 * each operation finds its handler through the #[Operation] attribute.
 */
return static function (Application $app): void {
    $app->get('/health', HealthAction::class, 'health');
    $app->get('/openapi.json', ContractAction::class, 'contract');

    // Incoming webhooks: the body is whatever the sender decided, authenticated by its
    // signature. Sources are declared by the contexts, under `acta.webhook_sources`.
    $app->post('/webhooks/{source}', WebhookReceiver::class, 'webhooks.receive');
};
