<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\TransactionManager;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A persistent projection that keeps a {@see ReadModel} up to date.
 *
 * ```php
 * $projections->readModelProjection('account_balances', $balances)
 *     ->fromCategory('account')
 *     ->when([
 *         AccountOpened::class => fn(array $state, AccountOpened $event) => $balances->open($event->id),
 *         MoneyDeposited::class => fn(array $state, MoneyDeposited $event) => $balances->add($event->id, $event->amount),
 *     ])
 *     ->run();
 * ```
 *
 * The projector initializes the read model on first run, empties it on reset and removes
 * it on deletion when asked to.
 */
final class ReadModelProjector extends PersistentProjection
{
    /**
     * @internal use {@see ProjectionManager::readModelProjection()}
     */
    public function __construct(
        string $name,
        EventStore $eventStore,
        ProjectionStore $store,
        ?TransactionManager $transactions,
        ProjectionOptions $options,
        public readonly ReadModel $readModel,
        LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($name, $eventStore, $store, $transactions, $options, $logger);
    }

    #[Override]
    protected function onStart(): void
    {
        if (!$this->readModel->isInitialized()) {
            $this->readModel->init();
        }
    }

    #[Override]
    protected function onPersist(): void
    {
        $this->readModel->persist();
    }

    #[Override]
    protected function onReset(): void
    {
        if ($this->readModel->isInitialized()) {
            $this->readModel->reset();
        }
    }

    #[Override]
    protected function onDelete(bool $deleteEmittedEvents): void
    {
        if ($deleteEmittedEvents && $this->readModel->isInitialized()) {
            $this->readModel->delete();
        }
    }
}
