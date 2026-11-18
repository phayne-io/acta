<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Console\ProjectionRegistry;
use Acta\EventSourcing\Exception\ProjectionAlreadyRunning;
use Acta\EventSourcing\Projection\PersistentProjection;
use InvalidArgumentException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs a projection: catches up with the event log, then keeps following it.
 *
 * Meant to run under a process supervisor, one process per projection. On SIGINT,
 * SIGTERM or SIGQUIT the projection finishes its current batch and stops; the
 * `projection:stop` command asks the same from another terminal.
 */
#[AsCommand('projection:run', 'Runs a projection until it is stopped')]
final class ProjectionRunCommand extends Command implements SignalableCommandInterface
{
    private ?PersistentProjection $running = null;

    public function __construct(
        private readonly ProjectionRegistry $projections,
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    #[Override]
    public function getSubscribedSignals(): array
    {
        // The constants only exist with the pcntl extension. SIGQUIT is what a container
        // built on the PHP-FPM image is stopped with: that image declares it.
        return defined('SIGINT') && defined('SIGTERM') && defined('SIGQUIT') ? [SIGINT, SIGTERM, SIGQUIT] : [];
    }

    #[Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->running?->stop();

        // Not exiting here lets the current batch finish and its checkpoint be written.
        return false;
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the projection')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Returns once the projection has caught up, instead of waiting for new events');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('name');
        $name = is_string($argument) ? $argument : '';
        $once = $input->getOption('once') === true;

        try {
            $this->running = $this->projections->get($name);
            $io->writeln(sprintf('Running projection <info>%s</info>%s.', $name, $once ? ' until it has caught up' : ''));
            $this->running->run(!$once);
        } catch (InvalidArgumentException|ProjectionAlreadyRunning $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $position = $this->running?->position();
            $this->running = null;
        }

        $io->success(sprintf('Projection %s stopped at position %s.', $name, $position));

        return self::SUCCESS;
    }
}
