<?php

declare(strict_types=1);

namespace Acta\EventSourcing;

use Closure;

/**
 * Port that delimits a unit of work.
 *
 * It replaces the `TransactionalEventStore` and the bus-bridge `TransactionManager` of
 * Prooph with one small contract shared by the event store, the read models and the
 * snapshot store, so that everything written while handling a command commits or rolls
 * back together.
 */
interface TransactionManager
{
    /**
     * Runs `$work` inside a transaction and returns its result.
     *
     * Calls can be nested: only the outermost call commits. When `$work` throws, the
     * transaction is rolled back and the exception is rethrown.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function transactional(Closure $work): mixed;

    /**
     * Tells whether a transaction opened by {@see transactional()} is in progress.
     */
    public function inTransaction(): bool;

    /**
     * Registers a callback to run once the current transaction has committed.
     *
     * The callback runs immediately when no transaction is in progress and is discarded
     * when the transaction rolls back.
     *
     * @param Closure(): void $callback
     */
    public function afterCommit(Closure $callback): void;
}
