<?php

declare(strict_types=1);

namespace Acta\Webhook\Testing;

use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\DeliveryStatus;
use Acta\Webhook\Outgoing\OutgoingDelivery;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What every {@see DeliveryQueue} must do. Extend it to check your own adapter.
 *
 * Requires `phpunit/phpunit`.
 */
abstract class DeliveryQueueContract extends TestCase
{
    abstract protected function createQueue(): DeliveryQueue;

    public function testDueDeliveriesAreReservedOldestFirst(): void
    {
        $queue = $this->createQueue();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $queue->enqueue(
            self::delivery('later', $now->modify('+1 minute')),
            self::delivery('second', $now->modify('-1 minute')),
            self::delivery('first', $now->modify('-2 minutes')),
            self::delivery('third', $now),
        );

        $reserved = $queue->reserve($now, 2, $now->modify('+60 seconds'));

        self::assertSame(['first', 'second'], array_column($reserved, 'id'));
        self::assertSame([1, 1], array_column($reserved, 'attempts'));
        self::assertEquals($now->modify('+60 seconds'), $reserved[0]->nextAttemptAt);
        self::assertSame('{"n":1}', $reserved[0]->body);
        self::assertSame('sub-1', $reserved[0]->subscriptionId);
        self::assertSame('account.opened', $reserved[0]->eventType);
        self::assertSame(DeliveryStatus::Pending, $reserved[0]->status);

        // What is reserved is not handed out again, until the reservation ends.
        self::assertSame(['third'], array_column($queue->reserve($now, 10, $now->modify('+60 seconds')), 'id'));
        self::assertSame([], $queue->reserve($now->modify('+59 seconds'), 10, $now->modify('+2 minutes')));

        $again = $queue->reserve($now->modify('+60 seconds'), 10, $now->modify('+3 minutes'));

        self::assertEqualsCanonicalizing(['first', 'second', 'third', 'later'], array_column($again, 'id'));
        self::assertSame(2, array_column($again, 'attempts', 'id')['first']);
        self::assertSame([], $queue->reserve($now, 0, $now));
    }

    public function testAKnownDeliveryIsNotEnqueuedTwice(): void
    {
        $queue = $this->createQueue();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        $queue->enqueue(self::delivery('one', $now));
        $queue->reserve($now, 10, $now->modify('+1 minute'));
        $queue->delivered('one', 204);
        $queue->enqueue(self::delivery('one', $now), self::delivery('two', $now));

        self::assertSame(['two'], array_column($queue->reserve($now->modify('+1 hour'), 10, $now->modify('+2 hours')), 'id'));
    }

    public function testAFailedDeliveryIsAttemptedAgainLater(): void
    {
        $queue = $this->createQueue();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $queue->enqueue(self::delivery('one', $now));

        $queue->reserve($now, 10, $now->modify('+1 minute'));
        $queue->retryAt('one', $now->modify('+5 minutes'), 503, 'The subscriber answered with status 503.');

        self::assertSame([], $queue->reserve($now->modify('+4 minutes'), 10, $now->modify('+10 minutes')));

        $retried = $queue->reserve($now->modify('+5 minutes'), 10, $now->modify('+10 minutes'));

        self::assertCount(1, $retried);
        self::assertSame(2, $retried[0]->attempts);
        self::assertSame(503, $retried[0]->lastStatus);
        self::assertSame('The subscriber answered with status 503.', $retried[0]->lastError);

        $queue->retryAt('one', $now->modify('+1 hour'), null, 'The subscriber could not be reached.');

        self::assertNull($queue->reserve($now->modify('+1 hour'), 10, $now->modify('+2 hours'))[0]->lastStatus);
    }

    public function testDeadLettersAreKeptAndCanBeRequeued(): void
    {
        $queue = $this->createQueue();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $queue->enqueue(
            self::delivery('old', $now, $now->modify('-1 day')),
            self::delivery('recent', $now, $now),
            self::delivery('fine', $now),
            self::delivery('stillborn', $now, $now->modify('-2 days'))->with(['status' => DeliveryStatus::Dead, 'lastError' => 'Invalid.']),
        );
        $queue->reserve($now, 10, $now->modify('+1 minute'));
        $queue->dead('old', 410, 'Gone.');
        $queue->dead('recent', null, 'Unreachable.');
        $queue->delivered('fine', 200);

        $dead = $queue->deadLetters();

        self::assertSame(['recent', 'old', 'stillborn'], array_column($dead, 'id'));
        self::assertSame([DeliveryStatus::Dead, DeliveryStatus::Dead, DeliveryStatus::Dead], array_column($dead, 'status'));
        self::assertSame([null, 410, null], array_column($dead, 'lastStatus'));
        self::assertSame(['Unreachable.', 'Gone.', 'Invalid.'], array_column($dead, 'lastError'));
        self::assertSame(['recent'], array_column($queue->deadLetters(1), 'id'));

        // Dead letters and acknowledged deliveries are never reserved.
        self::assertSame([], $queue->reserve($now->modify('+1 year'), 10, $now->modify('+2 years')));

        self::assertTrue($queue->requeue('old', $now->modify('+1 hour')));
        self::assertFalse($queue->requeue('old', $now));
        self::assertFalse($queue->requeue('fine', $now));
        self::assertFalse($queue->requeue('unknown', $now));
        self::assertSame(['recent', 'stillborn'], array_column($queue->deadLetters(), 'id'));

        $requeued = $queue->reserve($now->modify('+1 hour'), 10, $now->modify('+2 hours'));

        self::assertSame(['old'], array_column($requeued, 'id'));
        self::assertSame(1, $requeued[0]->attempts);
    }

    public function testUpdatingAnUnknownDeliveryDoesNothing(): void
    {
        $queue = $this->createQueue();
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        $queue->delivered('ghost', 200);
        $queue->retryAt('ghost', $now, null, 'x');
        $queue->dead('ghost', null, 'x');
        $queue->enqueue();

        self::assertSame([], $queue->deadLetters());
        self::assertSame([], $queue->reserve($now, 10, $now));
    }

    private static function delivery(string $id, DateTimeImmutable $nextAttemptAt, ?DateTimeImmutable $createdAt = null): OutgoingDelivery
    {
        return new OutgoingDelivery($id, 'sub-1', 'account.opened', '{"n":1}', $createdAt ?? $nextAttemptAt, $nextAttemptAt);
    }
}
