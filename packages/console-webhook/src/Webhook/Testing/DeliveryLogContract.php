<?php

declare(strict_types=1);

namespace Acta\Webhook\Testing;

use Acta\Webhook\Incoming\DeliveryLog;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What every {@see DeliveryLog} must do. Extend it to check your own adapter.
 *
 * Requires `phpunit/phpunit`.
 */
abstract class DeliveryLogContract extends TestCase
{
    /**
     * Returns an empty log.
     */
    abstract protected function createLog(): DeliveryLog;

    public function testADeliveryIsClaimedOnce(): void
    {
        $log = $this->createLog();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        self::assertTrue($log->claim('stripe', 'evt_1', $now, $now->modify('+1 hour')));
        self::assertFalse($log->claim('stripe', 'evt_1', $now, $now->modify('+1 hour')));
        self::assertFalse($log->claim('stripe', 'evt_1', $now->modify('+59 minutes'), $now->modify('+2 hours')));
    }

    public function testDeliveriesAreScopedToTheirSource(): void
    {
        $log = $this->createLog();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        self::assertTrue($log->claim('stripe', 'evt_1', $now, $now->modify('+1 hour')));
        self::assertTrue($log->claim('github', 'evt_1', $now, $now->modify('+1 hour')));
        self::assertTrue($log->claim('stripe', 'evt_2', $now, $now->modify('+1 hour')));
    }

    public function testAReleasedDeliveryCanBeClaimedAgain(): void
    {
        $log = $this->createLog();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        $log->claim('stripe', 'evt_1', $now, $now->modify('+1 hour'));
        $log->release('stripe', 'evt_1');
        $log->release('stripe', 'unknown');

        self::assertTrue($log->claim('stripe', 'evt_1', $now, $now->modify('+1 hour')));
    }

    public function testAnExpiredRecordIsForgotten(): void
    {
        $log = $this->createLog();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        $log->claim('stripe', 'evt_1', $now, $now->modify('+1 hour'));

        // The time that counts is the one given, whatever the clock of the machine says.
        self::assertTrue($log->claim('stripe', 'evt_1', $now->modify('+1 hour'), $now->modify('+2 hours')));
        self::assertFalse($log->claim('stripe', 'evt_1', $now->modify('+1 hour'), $now->modify('+2 hours')));
    }
}
