<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Postgres;

use Acta\EventSourcing\Postgres\PostgresSchema;
use PDO;
use Pdo\Pgsql;
use PDOException;

/**
 * Connection helpers for the tests that need a PostgreSQL server.
 *
 * The server is described by the `ACTA_TEST_POSTGRES_*` environment variables, see
 * `phpunit.xml.dist`. Tests are skipped when it cannot be reached.
 */
trait ConnectsToPostgres
{
    private static function connect(): Pgsql
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

        self::assertInstanceOf(Pgsql::class, $pdo);

        // Fail fast instead of hanging if a test ever leaves a lock behind.
        $pdo->exec("SET lock_timeout = '10s'");

        return $pdo;
    }

    /**
     * Returns a connection on empty tables.
     */
    private static function connectToFreshSchema(PostgresSchema $schema = new PostgresSchema()): Pgsql
    {
        $pdo = self::connect();

        $schema->drop($pdo);
        $schema->install($pdo);

        return $pdo;
    }
}
