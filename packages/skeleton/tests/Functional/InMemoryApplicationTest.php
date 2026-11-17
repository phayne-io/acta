<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\TestApplication;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The scenarios with all state kept in the process: fast, and run everywhere.
 */
#[CoversNothing]
final class InMemoryApplicationTest extends ApplicationTestCase
{
    #[Override]
    protected function boot(): TestApplication
    {
        return TestApplication::boot('memory');
    }

    /**
     * A context whose source of webhooks is misconfigured does not take the others down.
     */
    public function testASourceWithoutItsSecretIsLeftOut(): void
    {
        $app = TestApplication::boot('memory', ['acta' => ['webhooks' => ['secrets' => ['payments' => '']]]]);

        self::assertSame(404, $app->request('POST', '/webhooks/payments', '{}')->getStatusCode());
        self::assertSame(200, $app->request('GET', '/health')->getStatusCode());
        self::assertStringContainsString('cannot be used and is ignored', implode(' ', $app->logger->errors()));
    }

    /**
     * A client that is told 401 throws its token away; one that is told 503 retries.
     */
    public function testAnOutageOfTheIdentityProviderIsNotARefusal(): void
    {
        $app = TestApplication::boot('memory');
        $app->identityProvider->available = false;

        $response = $app->request('GET', '/accounts', null, $app->token());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('Retry-After'));
    }

    /**
     * Printing the help must work on a machine where nothing else does.
     */
    public function testListingTheCommandsBuildsNoneOfThem(): void
    {
        $app = TestApplication::boot('postgres', ['acta' => ['database' => ['dsn' => 'pgsql:host=192.0.2.1;port=1;dbname=nowhere']]]);

        $list = $app->console('list');

        self::assertSame(0, $list->getStatusCode(), $list->getDisplay());

        foreach (['es:migrate', 'projection:run', 'webhook:deliver', 'auth:login', 'make:context', 'banking:account:open'] as $command) {
            self::assertStringContainsString($command, $list->getDisplay());
        }
    }
}
