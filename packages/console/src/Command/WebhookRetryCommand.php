<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\EventSourcing\SystemClock;
use Acta\Webhook\Outgoing\DeliveryQueue;
use Override;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Puts dead letters back in the queue, once what made them fail is fixed.
 *
 * Requires `acta/webhook`.
 */
#[AsCommand('webhook:retry', 'Puts dead letters back in the queue')]
final class WebhookRetryCommand extends Command
{
    public function __construct(
        private readonly DeliveryQueue $queue,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('delivery', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Identifier(s) of the deliveries, as listed by webhook:dead-letters');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ids = $input->getArgument('delivery');
        $unknown = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            if (!is_string($id) || !$this->queue->requeue($id, $this->clock->now())) {
                $unknown[] = is_string($id) ? $id : '?';

                continue;
            }

            $io->writeln(sprintf('<info>✓</info> %s is due again', $id));
        }

        if ($unknown !== []) {
            $io->error(sprintf('Not a dead letter: %s.', implode(', ', $unknown)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
