<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Postgres;

use Acta\EventSourcing\Exception\InvalidArgument;
use PDO;
use Pdo\Pgsql;

/**
 * Pauses a projection until new events are appended, using LISTEN/NOTIFY.
 *
 * With the default `usleep()`, a projection that has caught up polls the event store at
 * a fixed interval. This waiter returns as soon as {@see PostgresEventStore} notifies an
 * append, and at the latest after the interval, so read models follow writes within
 * milliseconds without a tight polling loop:
 *
 * ```php
 * $options = new ProjectionOptions(
 *     sleepMicroseconds: 5_000_000,
 *     wait: new NotificationWaiter($pdo)(...),
 * );
 * ```
 *
 * It needs a connection created with `PDO::connect()` or `new Pdo\Pgsql()`, which can be
 * the one the projection already uses.
 */
final class NotificationWaiter
{
    private bool $listening = false;

    /**
     * @param string $channel channel the event store notifies, see {@see PostgresEventStore}
     *
     * @throws InvalidArgument when the channel is not a plain SQL identifier
     */
    public function __construct(
        private readonly Pgsql $pdo,
        private readonly string $channel = 'acta_events',
    ) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $channel) !== 1) {
            throw new InvalidArgument(sprintf('"%s" is not a valid notification channel.', $channel));
        }
    }

    /**
     * Blocks until a notification arrives or the delay elapses.
     */
    public function __invoke(int $microseconds): void
    {
        if (!$this->listening) {
            $this->pdo->exec('LISTEN ' . $this->channel);
            $this->listening = true;
        }

        $this->pdo->getNotify(PDO::FETCH_ASSOC, max(1, intdiv($microseconds, 1000)));

        // Several appends may have notified while we were busy: one wake-up is enough.
        while ($this->pdo->getNotify(PDO::FETCH_ASSOC) !== false);
    }
}
