<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Console\ProjectionRegistry;
use Acta\EventSourcing\Projection\ProjectionManager;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the projections with their status and the position they reached.
 */
#[AsCommand('projection:status', 'Lists the projections, their status and their position')]
final class ProjectionStatusCommand extends Command
{
    /**
     * @param ProjectionRegistry|null $registry when given, registered projections that never ran are listed too
     */
    public function __construct(
        private readonly ProjectionManager $projections,
        private readonly ?ProjectionRegistry $registry = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $known = $this->projections->names();
        $rows = [];

        foreach ($known as $name) {
            $rows[$name] = [$name, $this->projections->status($name)->value, (string) $this->projections->position($name)];
        }

        foreach (array_diff($this->registry?->names() ?? [], $known) as $name) {
            $rows[$name] = [$name, 'never run', '-'];
        }

        if ($rows === []) {
            $io->writeln('No projection yet.');

            return self::SUCCESS;
        }

        ksort($rows);
        $io->table(['Projection', 'Status', 'Position'], array_values($rows));

        return self::SUCCESS;
    }
}
