<?php

declare(strict_types=1);

namespace Acta\Console\Tests\Postgres;

use Acta\Console\Command\MigrateCommand;
use Acta\Console\Schema\StatementsSchema;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `es:migrate` against PostgreSQL, where a schema is installed in one transaction.
 */
#[CoversClass(MigrateCommand::class)]
#[CoversClass(StatementsSchema::class)]
#[Group('postgres')]
final class MigrateCommandTest extends TestCase
{
    use ConnectsToPostgres;

    private const array TABLES = ['migrate_things', 'migrate_never', 'migrate_half', 'migrate_after'];

    private PDO $pdo;

    #[Override]
    protected function setUp(): void
    {
        $this->pdo = self::connect();

        foreach (self::TABLES as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    public function testMigratingIsIdempotentAndCanBePreviewed(): void
    {
        $schema = new StatementsSchema('things', [
            'CREATE TABLE IF NOT EXISTS migrate_things (id INT PRIMARY KEY, name TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS migrate_things_name ON migrate_things (name)',
        ]);

        $first = new CommandTester(new MigrateCommand($this->pdo, $schema));
        $first->execute([]);

        self::assertSame(Command::SUCCESS, $first->getStatusCode(), $first->getDisplay());
        self::assertTrue($this->tableExists('migrate_things'));

        $again = new CommandTester(new MigrateCommand($this->pdo, $schema));
        $again->execute([]);

        self::assertSame(Command::SUCCESS, $again->getStatusCode());
        self::assertStringContainsString('✓ things (2 statements)', $again->getDisplay());
        self::assertStringContainsString('The database is up to date.', $again->getDisplay());

        $preview = new CommandTester(new MigrateCommand($this->pdo, new StatementsSchema('preview', ['CREATE TABLE migrate_never (id INT)'])));
        $preview->execute(['--dry-run' => true]);

        self::assertSame("-- preview\nCREATE TABLE migrate_never (id INT);\n", $preview->getDisplay(true));
        self::assertFalse($this->tableExists('migrate_never'));

        $empty = new CommandTester(new MigrateCommand($this->pdo));
        $empty->execute([]);

        self::assertStringContainsString('No schema is registered.', $empty->getDisplay());
    }

    public function testAFailingSchemaIsInstalledEntirelyOrNotAtAll(): void
    {
        $broken = new StatementsSchema('broken', ['CREATE TABLE migrate_half (id INT)', 'CREATE TABLE migrate_half (id INT)']);
        $tester = new CommandTester(new MigrateCommand($this->pdo, $broken, new StatementsSchema('after', ['CREATE TABLE migrate_after (id INT)'])));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Installing the broken schema failed', $tester->getDisplay());
        self::assertFalse($this->tableExists('migrate_half'));
        self::assertFalse($this->tableExists('migrate_after'));
        self::assertFalse($this->pdo->inTransaction());
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
        $statement->execute([$table]);

        return $statement->fetchColumn() === true;
    }
}
