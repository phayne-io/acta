<?php

declare(strict_types=1);

namespace Acta\Http\Testing;

use Acta\Http\Idempotency\IdempotencyStore;
use Acta\Http\Idempotency\StoredResponse;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What every {@see IdempotencyStore} must do. Extend it to check your own adapter.
 *
 * Requires `phpunit/phpunit`.
 */
abstract class IdempotencyStoreContract extends TestCase
{
    /**
     * Returns an empty store.
     */
    abstract protected function createStore(): IdempotencyStore;

    public function testAFreeKeyIsReservedOnce(): void
    {
        $store = $this->createStore();

        self::assertNull($store->acquire('key', 'print', self::now(), self::inOneHour()));

        $record = $store->acquire('key', 'print', self::now(), self::inOneHour());

        self::assertNotNull($record);
        self::assertSame('print', $record->fingerprint);
        self::assertNull($record->response);
    }

    public function testACompletedKeyReturnsItsResponse(): void
    {
        $store = $this->createStore();
        $response = new StoredResponse(201, ['Location' => ['/accounts/1'], 'Set-Cookie' => ['a=1', 'b=2']], "{\"id\":1}\n\x00\xff");

        $store->acquire('key', 'print', self::now(), self::inOneHour());
        $store->complete('key', $response);

        self::assertEquals($response, $store->acquire('key', 'other', self::now(), self::inOneHour())?->response);
        self::assertSame('print', $store->acquire('key', 'other', self::now(), self::inOneHour())?->fingerprint);
    }

    public function testAnEmptyResponseIsStored(): void
    {
        $store = $this->createStore();

        $store->acquire('key', 'print', self::now(), self::inOneHour());
        $store->complete('key', new StoredResponse(204, [], ''));

        self::assertEquals(new StoredResponse(204, [], ''), $store->acquire('key', 'print', self::now(), self::inOneHour())?->response);
    }

    public function testAReleasedKeyIsFreeAgain(): void
    {
        $store = $this->createStore();

        $store->acquire('key', 'print', self::now(), self::inOneHour());
        $store->release('key');

        self::assertNull($store->acquire('key', 'print', self::now(), self::inOneHour()));
    }

    public function testAnExpiredKeyIsFreeAgain(): void
    {
        $store = $this->createStore();

        $store->acquire('key', 'print', self::now(), self::inOneHour());
        $store->complete('key', new StoredResponse(200, [], 'old'));

        // The time that counts is the one given, whatever the clock of the machine says.
        self::assertNotNull($store->acquire('key', 'print', self::inOneHour()->modify('-1 second'), self::inOneHour()));
        self::assertNull($store->acquire('key', 'print', self::inOneHour(), self::inOneHour()->modify('+1 hour')));
        self::assertNull($store->acquire('key', 'print', self::inOneHour(), self::inOneHour()->modify('+1 hour'))?->response);
    }

    public function testKeysAreIndependent(): void
    {
        $store = $this->createStore();

        $store->acquire('one', 'print', self::now(), self::inOneHour());

        self::assertNull($store->acquire('two', 'print', self::now(), self::inOneHour()));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T12:00:00+00:00');
    }

    private static function inOneHour(): DateTimeImmutable
    {
        return self::now()->modify('+1 hour');
    }
}
