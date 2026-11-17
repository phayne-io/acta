<?php

declare(strict_types=1);

namespace Acta\ConsoleWebhook\Tests\Postgres;

use Acta\Webhook\Incoming\DeliveryLog;
use Acta\Webhook\Incoming\PostgresDeliveryLog;
use Acta\Webhook\Testing\DeliveryLogContract;
use DateTimeImmutable;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\Group;

#[Group('postgres')]
final class PostgresDeliveryLogTest extends DeliveryLogContract
{
    use ConnectsToPostgres;

    private const string TABLE = 'test_webhook_received';

    #[Override]
    protected function createLog(): DeliveryLog
    {
        return self::log(self::connect());
    }

    public function testExpiredRecordsArePurged(): void
    {
        $log = self::log(self::connect());
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        $log->claim('payments', 'old', $now, $now->modify('+1 minute'));
        $log->claim('payments', 'recent', $now, $now->modify('+1 hour'));

        self::assertSame(0, $log->purge($now));
        self::assertSame(1, $log->purge($now->modify('+1 minute')));
        self::assertSame(0, $log->purge($now->modify('+1 minute')));
        self::assertFalse($log->claim('payments', 'recent', $now->modify('+1 minute'), $now->modify('+1 hour')));
    }

    public function testTwoProcessesCannotClaimTheSameDelivery(): void
    {
        $first = self::log(self::connect());
        $second = new PostgresDeliveryLog(self::connect(), self::TABLE);
        $now = new DateTimeImmutable();

        self::assertTrue($first->claim('payments', 'msg_1', $now, $now->modify('+1 hour')));
        self::assertFalse($second->claim('payments', 'msg_1', $now, $now->modify('+1 hour')));
    }

    public function testAClaimIsUndoneWithItsTransaction(): void
    {
        $pdo = self::connect();
        $log = self::log($pdo);
        $now = new DateTimeImmutable();

        $pdo->beginTransaction();
        self::assertTrue($log->claim('payments', 'msg_1', $now, $now->modify('+1 hour')));
        $pdo->rollBack();

        // The command failed and its transaction was rolled back: the delivery is new again.
        self::assertTrue($log->claim('payments', 'msg_1', $now, $now->modify('+1 hour')));
    }

    private static function log(PDO $pdo): PostgresDeliveryLog
    {
        $log = new PostgresDeliveryLog($pdo, self::TABLE);
        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        array_map($pdo->exec(...), $log->statements());

        return $log;
    }
}
