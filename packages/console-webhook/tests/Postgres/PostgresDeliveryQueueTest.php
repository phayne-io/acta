<?php

declare(strict_types=1);

namespace Acta\ConsoleWebhook\Tests\Postgres;

use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\OutgoingDelivery;
use Acta\Webhook\Outgoing\PostgresDeliveryQueue;
use Acta\Webhook\Testing\DeliveryQueueContract;
use DateTimeImmutable;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\Group;

#[Group('postgres')]
final class PostgresDeliveryQueueTest extends DeliveryQueueContract
{
    use ConnectsToPostgres;

    private const string TABLE = 'test_webhook_deliveries';

    #[Override]
    protected function createQueue(): DeliveryQueue
    {
        return self::queue(self::connect());
    }

    public function testTwoWorkersNeverReserveTheSameDelivery(): void
    {
        $first = self::connect();
        $second = self::connect();
        $queue = self::queue($first);
        $other = new PostgresDeliveryQueue($second, self::TABLE);
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $queue->enqueue(...array_map(
            static fn(int $n): OutgoingDelivery => new OutgoingDelivery('msg_' . $n, 'sub-1', 'account.opened', '{}', $now, $now->modify("+{$n} seconds")),
            range(1, 6),
        ));

        // The first worker has reserved its batch and not committed yet.
        $first->beginTransaction();
        $mine = $queue->reserve($now->modify('+1 minute'), 4, $now->modify('+2 minutes'));
        // The second one does not wait for it: it skips the locked rows and takes the others.
        $theirs = $other->reserve($now->modify('+1 minute'), 4, $now->modify('+2 minutes'));
        $first->commit();

        self::assertSame(['msg_1', 'msg_2', 'msg_3', 'msg_4'], array_column($mine, 'id'));
        self::assertSame(['msg_5', 'msg_6'], array_column($theirs, 'id'));
    }

    public function testBodiesAndErrorsAreStoredAsTheyAre(): void
    {
        $queue = self::queue(self::connect());
        $now = new DateTimeImmutable('2026-01-01T12:00:00.123+00:00');
        $body = '{"owner":"Zoë \"Z\" O\'Neil","note":"line\nbreak","amount":"12000"}';

        $queue->enqueue(new OutgoingDelivery('msg_1', 'sub-1', 'account.opened', $body, $now, $now));
        $reserved = $queue->reserve($now, 1, $now->modify('+1 minute'));

        self::assertSame($body, $reserved[0]->body);
        self::assertEquals($now, $reserved[0]->createdAt);

        $queue->dead('msg_1', 500, "It's broken: <html>\u{1F4A5}</html>");

        self::assertSame("It's broken: <html>\u{1F4A5}</html>", $queue->deadLetters()[0]->lastError);
    }

    public function testAcknowledgedDeliveriesArePurged(): void
    {
        $queue = self::queue(self::connect());
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $queue->enqueue(
            new OutgoingDelivery('old', 'sub-1', 'account.opened', '{}', $now->modify('-40 days'), $now),
            new OutgoingDelivery('recent', 'sub-1', 'account.opened', '{}', $now->modify('-1 day'), $now),
            new OutgoingDelivery('old-but-pending', 'sub-1', 'account.opened', '{}', $now->modify('-40 days'), $now->modify('+1 day')),
        );
        $queue->reserve($now, 10, $now->modify('+1 minute'));
        $queue->delivered('old', 200);
        $queue->delivered('recent', 200);

        self::assertSame(1, $queue->purgeDelivered($now->modify('-30 days')));
        self::assertSame(0, $queue->purgeDelivered($now->modify('-30 days')));
        // What was not delivered is kept, however old.
        self::assertSame(['old-but-pending'], array_column($queue->reserve($now->modify('+1 day'), 10, $now->modify('+2 days')), 'id'));
    }

    private static function queue(PDO $pdo): PostgresDeliveryQueue
    {
        $queue = new PostgresDeliveryQueue($pdo, self::TABLE);
        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        array_map($pdo->exec(...), $queue->statements());

        return $queue;
    }
}
