<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Webhook;

use Acta\Webhook\Incoming\WebhookSource;
use Acta\Webhook\Signature\StandardWebhooks;
use App\Platform\Container\Resolve;
use App\Platform\Settings;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * Declares the payment provider as a source of webhooks: its name, how its signature is
 * verified and who translates what it says.
 */
final class PaymentsWebhookSource
{
    /** Name of the source: its webhooks are received at `POST /webhooks/payments`. */
    public const string NAME = 'payments';

    /** Service identifier of the source. */
    public const string ID = 'banking.webhook.payments';

    public static function create(ContainerInterface $container): WebhookSource
    {
        return new WebhookSource(
            self::NAME,
            // The provider signs with the Standard Webhooks scheme. For a provider with a scheme of its
            // own, a signature in a single header for instance, use Acta\Webhook\Signature\HmacSignature.
            new StandardWebhooks(
                Settings::of($container)->string('acta.webhooks.secrets.' . self::NAME),
                clock: Resolve::from($container, ClockInterface::class),
            ),
            new PaymentProviderTranslator(),
        );
    }
}
