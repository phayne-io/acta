<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Postgres;

use Acta\Http\Exception\ContractError;
use Acta\Http\Idempotency\IdempotencyStore;
use Acta\Http\Idempotency\PostgresIdempotencyStore;
use Acta\Http\Idempotency\StoredResponse;
use Acta\Http\Testing\IdempotencyStoreContract;
use DateTimeImmutable;
use Override;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * The server is described by the `ACTA_TEST_POSTGRES_*` environment variables, see
 * `phpunit.xml.dist`. Tests are skipped when it cannot be reached.
 */
#[Group('postgres')]
final class PostgresIdempotencyStoreTest extends IdempotencyStoreContract
{
    private const string TABLE = 'test_http_idempotency';

    #[Override]
    protected function createStore(): IdempotencyStore
    {
        return self::store(self::connect());
    }

    public function testInstallingTwiceIsHarmless(): void
    {
        $store = self::store(self::connect());
        $store->install();

        self::assertCount(2, $store->statements());
    }

    public function testExpiredKeysArePurged(): void
    {
        $store = self::store(self::connect());
        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        $store->acquire('old', 'print', $now, $now->modify('+1 minute'));
        $store->acquire('older', 'print', $now, $now->modify('+2 minutes'));
        $store->acquire('recent', 'print', $now, $now->modify('+1 hour'));

        self::assertSame(0, $store->purge($now));
        self::assertSame(2, $store->purge($now->modify('+2 minutes')));
        self::assertSame(0, $store->purge($now->modify('+2 minutes')));
        self::assertNotNull($store->acquire('recent', 'print', $now->modify('+2 minutes'), $now->modify('+1 hour')));
    }

    public function testACompletedKeyIsNotReleased(): void
    {
        $store = self::store(self::connect());
        $now = new DateTimeImmutable();

        $store->acquire('key', 'print', $now, $now->modify('+1 hour'));
        $store->complete('key', new StoredResponse(201, [], 'done'));
        $store->release('key');

        self::assertSame('done', $store->acquire('key', 'print', $now, $now->modify('+1 hour'))?->response?->body);
    }

    public function testTwoConnectionsCannotReserveTheSameKey(): void
    {
        $first = self::store(self::connect());
        $second = new PostgresIdempotencyStore(self::connect(), self::TABLE);
        $now = new DateTimeImmutable();

        self::assertNull($first->acquire('key', 'print', $now, $now->modify('+1 hour')));
        self::assertNotNull($second->acquire('key', 'print', $now, $now->modify('+1 hour')));
    }

    public function testTheTableNameMustBeAPlainIdentifier(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('is not a plain SQL identifier');

        new PostgresIdempotencyStore(self::connect(), 'keys; DROP TABLE users');
    }

    private static function store(PDO $pdo): PostgresIdempotencyStore
    {
        $store = new PostgresIdempotencyStore($pdo, self::TABLE);

        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $store->install();

        return $store;
    }

    private static function connect(): PDO
    {
        $dsn = getenv('ACTA_TEST_POSTGRES_DSN');

        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('ACTA_TEST_POSTGRES_DSN is not set.');
        }

        try {
            $pdo = PDO::connect(
                $dsn,
                getenv('ACTA_TEST_POSTGRES_USER') ?: null,
                getenv('ACTA_TEST_POSTGRES_PASSWORD') ?: null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $exception) {
            self::markTestSkipped('PostgreSQL is not reachable: ' . $exception->getMessage());
        }

        $pdo->exec("SET lock_timeout = '10s'");

        return $pdo;
    }
}
