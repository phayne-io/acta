<?php

declare(strict_types=1);

namespace Acta\ConsoleWebhook\Tests\Postgres;

use PDO;
use PDOException;

/**
 * Connects the tests to PostgreSQL.
 *
 * The server is described by the `ACTA_TEST_POSTGRES_*` environment variables, see
 * `phpunit.xml.dist`. Tests are skipped when it cannot be reached.
 */
trait ConnectsToPostgres
{
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

        // Fail fast instead of hanging if a test ever leaves a lock behind.
        $pdo->exec("SET lock_timeout = '10s'");

        return $pdo;
    }
}
