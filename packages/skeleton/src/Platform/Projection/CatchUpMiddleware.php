<?php

declare(strict_types=1);

namespace App\Platform\Projection;

use Acta\Console\ProjectionRegistry;
use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Exception\ProjectionAlreadyRunning;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\TransactionManager;
use Closure;
use Override;

/**
 * Brings projections up to date as soon as a command has committed.
 *
 * Projections normally run in their own processes (`bin/console projection:run`), and
 * read models lag a little behind the writes. This middleware removes the lag where no
 * such process runs: on a developer machine and in tests. It is a convenience, not a
 * guarantee: a projection that is already running elsewhere is left alone, and events
 * of transactions still open in other processes are picked up later. Production runs
 * the projections as workers and leaves `acta.projections.after_command` empty.
 *
 * Place it before the transaction middleware, so that it acts after the commit.
 */
final readonly class CatchUpMiddleware implements Middleware
{
    /**
     * @param list<string>                  $projections names of the projections to bring up to date
     * @param Closure(): ProjectionRegistry $registry    resolved on first use, since most commands of a
     *                                                   production process never need it
     */
    public function __construct(
        private array $projections,
        private Closure $registry,
        private TransactionManager $transactions,
    ) {}

    #[Override]
    public function handle(Envelope $envelope, Closure $next): mixed
    {
        $result = $next($envelope);

        if ($this->projections === []) {
            return $result;
        }

        // A command handled inside a wider transaction is not visible to projections before that one commits.
        $this->transactions->inTransaction()
            ? $this->transactions->afterCommit($this->catchUp(...))
            : $this->catchUp();

        return $result;
    }

    private function catchUp(): void
    {
        $registry = ($this->registry)();

        foreach ($this->projections as $name) {
            try {
                $registry->get($name)->run(keepRunning: false);
            } catch (ProjectionAlreadyRunning) {
                // A worker has it: the read model will follow.
            }
        }
    }
}
