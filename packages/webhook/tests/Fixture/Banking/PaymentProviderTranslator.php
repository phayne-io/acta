<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Fixture\Banking;

use Acta\Webhook\Exception\UnprocessableWebhook;
use Acta\Webhook\Incoming\IncomingWebhook;
use Acta\Webhook\Incoming\WebhookTranslator;
use Money\Currency;
use Money\Money;
use Override;

/**
 * Translates the webhooks of an imaginary payment provider into banking commands.
 */
final readonly class PaymentProviderTranslator implements WebhookTranslator
{
    #[Override]
    public function translate(IncomingWebhook $webhook): iterable
    {
        $type = $webhook->payload['type'] ?? null;
        $payment = is_array($webhook->payload['data'] ?? null) ? $webhook->payload['data'] : [];
        $string = static fn(string $name): string => is_string($payment[$name] ?? null) && $payment[$name] !== ''
            ? $payment[$name]
            : throw new UnprocessableWebhook(sprintf('The payment has no "%s".', $name));

        $amount = static function () use ($string): int {
            $amount = $string('amount');

            return ctype_digit($amount) ? (int) $amount : throw new UnprocessableWebhook('The amount of the payment is not a whole number.');
        };

        return match ($type) {
            // Of no interest to the application: acknowledged, nothing dispatched.
            'ping', 'payment.created' => [],
            'payment.succeeded' => [
                new DepositMoney($string('account'), new Money($amount(), new Currency($string('currency'))), $string('id')),
            ],
            'payment.disputed' => [
                new FlagPayment($string('id'), 'disputed'),
                new FlagPayment($string('id'), 'frozen'),
            ],
            default => throw new UnprocessableWebhook(sprintf('Unknown event type "%s".', is_string($type) ? $type : '')),
        };
    }
}
