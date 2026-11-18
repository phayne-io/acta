<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\TestApplication;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * The same scenarios against PostgreSQL, as in production.
 *
 * Skipped when the server of `phpunit.xml.dist` cannot be reached; `make test` runs them
 * against the database of the local stack.
 */
#[CoversNothing]
#[Group('postgres')]
final class PostgresApplicationTest extends ApplicationTestCase
{
    #[Override]
    protected function boot(): TestApplication
    {
        $app = TestApplication::boot('postgres');

        try {
            $app->get(PDO::class);
        } catch (Throwable $exception) {
            self::markTestSkipped('PostgreSQL is not reachable: ' . ($exception->getPrevious() ?? $exception)->getMessage());
        }

        $app->resetDatabase();

        return $app;
    }

    public function testMigratingTwiceChangesNothing(): void
    {
        $again = $this->app->console('es:migrate');

        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
        self::assertStringContainsString('banking_accounts', $this->app->console('es:migrate', ['--dry-run' => true])->getDisplay());
    }
}
