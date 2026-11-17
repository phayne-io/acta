<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Secondary\Webhook;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\Webhook\Outgoing\IntegrationEvent;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use App\Banking\Domain\Event\AccountOpened;
use App\Banking\Domain\Event\MoneyDeposited;
use App\Banking\Domain\Event\MoneyWithdrawn;
use Money\Money;
use Override;

/**
 * Secondary adapter: what the bank tells the outside world, in its public vocabulary.
 *
 * Domain events are free to change with the model; the events published here are a
 * contract with the subscribers, described by the `webhooks` section of
 * `openapi/openapi.yaml`. This class is the only place where one becomes the other.
 */
final readonly class PublicEvents implements IntegrationEventMapper
{
    #[Override]
    public function map(RecordedEvent $event): ?IntegrationEvent
    {
        $message = $event->message;

        return match (true) {
            $message instanceof AccountOpened => new IntegrationEvent('account.opened', [
                'accountId' => $message->accountId->value,
                'owner' => $message->owner,
                'currency' => $message->currency->getCode(),
            ]),
            $message instanceof MoneyDeposited => new IntegrationEvent('account.credited', [
                'accountId' => $message->accountId->value,
                'amount' => self::amount($message->money),
            ]),
            $message instanceof MoneyWithdrawn => new IntegrationEvent('account.debited', [
                'accountId' => $message->accountId->value,
                'amount' => self::amount($message->money),
            ]),
            default => null,
        };
    }

    /**
     * @return array{amount: string, currency: string}
     */
    private static function amount(Money $money): array
    {
        return ['amount' => $money->getAmount(), 'currency' => $money->getCurrency()->getCode()];
    }
}
