<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Postgres;

use Acta\EventSourcing\NestedTransactions;
use Acta\EventSourcing\TransactionManager;
use PDO;

/**
 * Transaction manager backed by a PDO connection.
 *
 * Give the same instance, and the same PDO connection, to the event store, the
 * projection store, the snapshot store and your read models: what they write while a
 * command is handled then commits or rolls back as a whole.
 *
 * When the connection is already in a transaction started elsewhere, for instance by a
 * framework, the manager joins it and leaves the commit to whoever opened it. It cannot
 * observe that commit: after-commit callbacks then run when the outermost
 * {@see transactional()} call returns, before the surrounding transaction is settled.
 * Let this manager own the transaction if you rely on after-commit callbacks, as
 * {@see \Acta\EventSourcing\EventStore\PublishingEventStore} does.
 */
final class PdoTransactionManager implements TransactionManager
{
    use NestedTransactions;

    private bool $joined = false;

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    private function beginTransaction(): void
    {
        $this->joined = $this->pdo->inTransaction();

        if (!$this->joined) {
            $this->pdo->beginTransaction();
        }
    }

    private function commitTransaction(): void
    {
        if (!$this->joined) {
            $this->pdo->commit();
        }
    }

    private function rollBackTransaction(): void
    {
        if (!$this->joined && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
