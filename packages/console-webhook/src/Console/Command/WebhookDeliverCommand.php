<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Webhook\Outgoing\WebhookSender;
use Closure;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends the outgoing webhooks that are due.
 *
 * Run it from a scheduler, or with `--watch` under a process supervisor. Several
 * instances can run side by side: a delivery is attempted by one of them only.
 */
#[AsCommand('webhook:deliver', 'Sends the outgoing webhooks that are due')]
final class WebhookDeliverCommand extends Command implements SignalableCommandInterface
{
    private bool $stopping = false;

    /**
     * @param Closure(int): mixed $wait pauses for the given number of seconds
     */
    public function __construct(
        private readonly WebhookSender $sender,
        private readonly Closure $wait = sleep(...),
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    #[Override]
    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') && defined('SIGTERM') ? [SIGINT, SIGTERM] : [];
    }

    #[Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopping = true;

        // The batch in progress is finished first.
        return false;
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Deliveries attempted per batch', '100')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Keeps running, waiting for deliveries to become due')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Seconds to wait when nothing is due, with --watch', '5');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, self::integer($input->getOption('limit'), 100));
        $interval = max(1, self::integer($input->getOption('interval'), 5));
        $watch = $input->getOption('watch') === true;
        $this->stopping = false;

        do {
            $report = $this->sender->deliverDue($limit);

            if ($report->total() > 0 || !$watch) {
                $output->writeln(sprintf(
                    '%d delivered, %d to retry, %d given up',
                    $report->delivered,
                    $report->retried,
                    $report->dead,
                ));
            }

            // A full batch means more is probably waiting: do not pause.
            if ($watch && !$this->stopRequested() && $report->total() < $limit) {
                ($this->wait)($interval);
            }
        } while ($watch && !$this->stopRequested());

        return self::SUCCESS;
    }

    /**
     * Reads the flag a signal may set at any time while the loop runs.
     */
    private function stopRequested(): bool
    {
        return $this->stopping;
    }

    private static function integer(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
