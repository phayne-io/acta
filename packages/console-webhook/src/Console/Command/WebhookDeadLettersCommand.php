<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\OutgoingDelivery;
use DateTimeInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the outgoing webhooks that were given up, with the reason.
 */
#[AsCommand('webhook:dead-letters', 'Lists the outgoing webhooks that were given up')]
final class WebhookDeadLettersCommand extends Command
{
    public function __construct(
        private readonly DeliveryQueue $queue,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many to list, most recent first', '50');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit');
        $dead = $this->queue->deadLetters(is_numeric($limit) ? max(1, (int) $limit) : 50);

        if ($dead === []) {
            $io->writeln('No dead letter.');

            return self::SUCCESS;
        }

        $io->table(
            ['Delivery', 'Event', 'Subscription', 'Created', 'Attempts', 'Reason'],
            array_map(static fn(OutgoingDelivery $delivery): array => [
                $delivery->id,
                $delivery->eventType,
                $delivery->subscriptionId,
                $delivery->createdAt->format(DateTimeInterface::RFC3339),
                $delivery->attempts,
                $delivery->lastError ?? '',
            ], $dead),
        );
        $io->writeln('Send one again with <info>webhook:retry <delivery></info>.');

        return self::SUCCESS;
    }
}
