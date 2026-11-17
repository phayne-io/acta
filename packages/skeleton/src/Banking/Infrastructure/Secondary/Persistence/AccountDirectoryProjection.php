<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Secondary\Persistence;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\Projection\PersistentProjection;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use App\Banking\Application\Query\AccountView;
use App\Banking\Domain\AccountId;
use App\Banking\Domain\Event\AccountOpened;
use App\Banking\Domain\Event\MoneyDeposited;
use App\Banking\Domain\Event\MoneyWithdrawn;
use App\Platform\Projection\ProjectionDefinition;
use LogicException;
use Money\Money;
use Override;

/**
 * Keeps the read model of accounts up to date from the events of accounts.
 *
 * Registered as the `banking_accounts` projection: `bin/console projection:run
 * banking_accounts` runs it, `projection:rebuild` replays the whole history into it.
 */
final readonly class AccountDirectoryProjection implements ProjectionDefinition
{
    public const string NAME = 'banking_accounts';

    public function __construct(
        private WritableAccountDirectory $directory,
    ) {}

    #[Override]
    public function build(ProjectionManager $projections, string $name, ProjectionOptions $options): PersistentProjection
    {
        return $projections
            ->readModelProjection($name, $this->directory, $options)
            ->fromCategory(EventSourcedAccountRepository::CATEGORY)
            ->when([
                AccountOpened::class => fn(array $state, AccountOpened $event, RecordedEvent $recorded) => $this->directory->save(
                    new AccountView($event->accountId->value, $event->owner, new Money(0, $event->currency), $recorded->streamVersion),
                ),
                MoneyDeposited::class => fn(array $state, MoneyDeposited $event, RecordedEvent $recorded) => $this->move(
                    $event->accountId,
                    $event->money,
                    $recorded,
                ),
                MoneyWithdrawn::class => fn(array $state, MoneyWithdrawn $event, RecordedEvent $recorded) => $this->move(
                    $event->accountId,
                    $event->money->negative(),
                    $recorded,
                ),
            ]);
    }

    private function move(AccountId $id, Money $money, RecordedEvent $recorded): void
    {
        $account = $this->directory->find($id)
            ?? throw new LogicException(sprintf('The read model has no account "%s": its events were not projected in order.', $id));

        $this->directory->save($account->after($money, $recorded->streamVersion));
    }
}
