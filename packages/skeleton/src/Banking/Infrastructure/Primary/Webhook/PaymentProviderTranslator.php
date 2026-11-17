<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Webhook;

use Acta\Webhook\Exception\UnprocessableWebhook;
use Acta\Webhook\Incoming\IncomingWebhook;
use Acta\Webhook\Incoming\WebhookTranslator;
use App\Banking\Application\Command\DepositMoney;
use App\Banking\Domain\AccountId;
use InvalidArgumentException;
use Money\Currency;
use Money\Money;
use Override;

/**
 * Primary adapter: says in the words of the bank what the payment provider reports.
 *
 * It is an anti-corruption layer. The payload below is the provider's and will change
 * on its schedule; nothing past this class knows its shape:
 *
 * ```json
 * { "type": "payment.succeeded",
 *   "data": { "id": "pay_1", "account": "<account id>", "amount": "12000", "currency": "EUR" } }
 * ```
 *
 * The receiver has already verified the signature and refused duplicates.
 */
final readonly class PaymentProviderTranslator implements WebhookTranslator
{
    #[Override]
    public function translate(IncomingWebhook $webhook): iterable
    {
        return match ($webhook->payload['type'] ?? null) {
            'payment.succeeded' => [$this->deposit($webhook->payload['data'] ?? null)],
            // Events of no interest are acknowledged, or the provider would send them again.
            'payment.created', 'ping' => [],
            default => throw new UnprocessableWebhook('This event type is not handled.'),
        };
    }

    private function deposit(mixed $payment): DepositMoney
    {
        if (
            !is_array($payment)
            || !is_string($payment['id'] ?? null)
            || !is_string($payment['account'] ?? null)
            || !is_string($payment['currency'] ?? null)
            || $payment['currency'] === ''
            || !is_string($payment['amount'] ?? null)
            || !ctype_digit($payment['amount'])
        ) {
            throw new UnprocessableWebhook('A payment needs an "id", an "account", an "amount" in minor units and a "currency".');
        }

        try {
            $account = AccountId::fromString($payment['account']);
        } catch (InvalidArgumentException $exception) {
            throw new UnprocessableWebhook('The "account" of the payment is not an account identifier.', previous: $exception);
        }

        return new DepositMoney($account, new Money($payment['amount'], new Currency($payment['currency'])), $payment['id']);
    }
}
