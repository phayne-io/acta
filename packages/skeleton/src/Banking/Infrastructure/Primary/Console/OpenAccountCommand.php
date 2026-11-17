<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Console;

use Acta\Console\ConsoleMetadata;
use Acta\EventSourcing\Bus\CommandBus;
use App\Banking\Application\Command\OpenAccount;
use App\Banking\Domain\AccountId;
use Money\Currency;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Primary adapter: opens an account from the command line.
 *
 * It dispatches the same command as `POST /accounts`: a use case has one implementation,
 * whatever drives it.
 */
#[AsCommand('banking:account:open', 'Opens a bank account')]
final class OpenAccountCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('owner', InputArgument::REQUIRED, 'In whose name the account is opened')
            ->addOption('currency', 'c', InputOption::VALUE_REQUIRED, 'Currency the account holds (ISO 4217)', 'EUR');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = AccountId::generate();
        $owner = $input->getArgument('owner');
        $currency = $input->getOption('currency');

        $this->commands->dispatch(
            new OpenAccount($id, is_string($owner) ? $owner : '', new Currency(is_string($currency) && $currency !== '' ? $currency : 'EUR')),
            // Who acts, for the audit trail: the person logged in on this machine.
            ConsoleMetadata::create(),
        );

        new SymfonyStyle($input, $output)->success(sprintf('Account %s opened.', $id));

        return self::SUCCESS;
    }
}
