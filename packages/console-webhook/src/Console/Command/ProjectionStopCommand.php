<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\EventSourcing\Exception\ProjectionNotFound;
use Acta\EventSourcing\Projection\ProjectionManager;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asks a projection running in another process to stop after its current batch.
 */
#[AsCommand('projection:stop', 'Asks a running projection to stop')]
final class ProjectionStopCommand extends Command
{
    public function __construct(
        private readonly ProjectionManager $projections,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Name of the projection');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('name');
        $name = is_string($argument) ? $argument : '';

        try {
            $this->projections->stop($name);
        } catch (ProjectionNotFound $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Projection %s was asked to stop. It does so after its current batch.', $name));

        return self::SUCCESS;
    }
}
