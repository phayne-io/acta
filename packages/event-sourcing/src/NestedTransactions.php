<?php

declare(strict_types=1);

namespace Acta\EventSourcing;

use Acta\EventSourcing\Exception\TransactionAborted;
use Closure;
use Throwable;

/**
 * Shared implementation of {@see TransactionManager} for adapters.
 *
 * It tracks nesting, defers after-commit callbacks to the outermost commit and marks the
 * transaction as rollback-only as soon as a nested unit of work fails, even if the caller
 * swallows the exception.
 *
 * @internal
 */
trait NestedTransactions
{
    private int $transactionDepth = 0;

    private bool $rollbackOnly = false;

    /** @var list<Closure(): void> */
    private array $afterCommitCallbacks = [];

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function transactional(Closure $work): mixed
    {
        if ($this->transactionDepth === 0) {
            $this->beginTransaction();
            $this->rollbackOnly = false;
        }

        $this->transactionDepth++;

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $this->transactionDepth--;
            $this->rollbackOnly = true;

            if ($this->transactionDepth === 0) {
                $this->abort();
            }

            throw $exception;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth > 0) {
            return $result;
        }

        if ($this->rollbackOnly) {
            $this->abort();

            throw new TransactionAborted('A nested unit of work failed: the transaction was rolled back.');
        }

        // Taken before committing: if the commit itself fails, the callbacks are dropped
        // instead of leaking into the next transaction.
        $callbacks = $this->afterCommitCallbacks;
        $this->afterCommitCallbacks = [];

        $this->commitTransaction();

        foreach ($callbacks as $callback) {
            $callback();
        }

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * @param Closure(): void $callback
     */
    public function afterCommit(Closure $callback): void
    {
        if ($this->transactionDepth === 0) {
            $callback();

            return;
        }

        $this->afterCommitCallbacks[] = $callback;
    }

    private function abort(): void
    {
        $this->afterCommitCallbacks = [];
        $this->rollbackOnly = false;
        $this->rollBackTransaction();
    }

    abstract private function beginTransaction(): void;

    abstract private function commitTransaction(): void;

    abstract private function rollBackTransaction(): void;
}
