<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Console\Schema\Schema;
use Override;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Creates the tables of the event store and of the other adapters.
 *
 * Statements are idempotent, so the command is safe to run at every deployment. Each
 * schema is installed in one transaction: it is there entirely or not at all.
 */
#[AsCommand('es:migrate', 'Creates the tables of the event store and of the other Acta adapters')]
final class MigrateCommand extends Command
{
    /** @var list<Schema> */
    private readonly array $schemas;

    public function __construct(
        private readonly PDO $pdo,
        Schema ...$schemas,
    ) {
        $this->schemas = array_values($schemas);

        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Prints the statements instead of running them');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run') === true) {
            foreach ($this->schemas as $schema) {
                $output->writeln(sprintf('-- %s', $schema->name()));

                foreach ($schema->statements() as $statement) {
                    $output->writeln(rtrim(trim($statement), ';') . ';');
                }
            }

            return self::SUCCESS;
        }

        foreach ($this->schemas as $schema) {
            $statements = $schema->statements();

            try {
                $this->pdo->beginTransaction();
                array_map($this->pdo->exec(...), $statements);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                $io->error(sprintf('Installing the %s schema failed: %s', $schema->name(), $exception->getMessage()));

                return self::FAILURE;
            }

            $io->writeln(sprintf('<info>✓</info> %s (%d statement%s)', $schema->name(), count($statements), count($statements) === 1 ? '' : 's'));
        }

        $io->success($this->schemas === [] ? 'No schema is registered.' : 'The database is up to date.');

        return self::SUCCESS;
    }
}
