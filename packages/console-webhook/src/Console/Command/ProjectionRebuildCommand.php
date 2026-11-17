<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Console\ProjectionRegistry;
use Acta\EventSourcing\Exception\ProjectionAlreadyRunning;
use InvalidArgumentException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds a projection from the first event: empties its read model, then replays the log.
 *
 * Use it after changing how a read model is built. While the rebuild runs, the read
 * model is incomplete: rebuild under another name and switch when that matters.
 */
#[AsCommand('projection:rebuild', 'Empties a projection and replays the event log into it')]
final class ProjectionRebuildCommand extends Command
{
    public function __construct(
        private readonly ProjectionRegistry $projections,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the projection')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Does not ask for confirmation');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('name');
        $name = is_string($argument) ? $argument : '';

        try {
            $projection = $this->projections->get($name);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $question = sprintf('Empty the read model of "%s" and replay every event into it?', $name);

        if ($input->getOption('force') !== true && !$io->confirm($question, false)) {
            $io->warning('Nothing was changed. Pass --force to rebuild without being asked.');

            return self::FAILURE;
        }

        try {
            $projection->reset();
            $projection->run(false);
        } catch (ProjectionAlreadyRunning $exception) {
            $io->error($exception->getMessage() . ' Stop it first with projection:stop.');

            return self::FAILURE;
        }

        $io->success(sprintf('Projection %s was rebuilt up to position %s.', $name, $projection->position()));

        return self::SUCCESS;
    }
}
