<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Console;

use Acta\EventSourcing\Bus\QueryBus;
use App\Banking\Application\Query\AccountView;
use App\Banking\Application\Query\GetAccount;
use App\Banking\Domain\AccountId;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Primary adapter: shows an account from the command line, as the read model knows it.
 */
#[AsCommand('banking:account:show', 'Shows a bank account')]
final class ShowAccountCommand extends Command
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('account', InputArgument::REQUIRED, 'Identifier of the account');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('account');
        $account = $this->queries->ask(new GetAccount(AccountId::fromString(is_string($id) ? $id : '')));

        if (!$account instanceof AccountView) {
            $io->error('There is no such account. A new account appears once its projection has run.');

            return self::FAILURE;
        }

        $io->definitionList(
            ['Account' => $account->id],
            ['Owner' => $account->owner],
            ['Balance' => sprintf('%s %s (minor units)', $account->balance->getAmount(), $account->balance->getCurrency())],
            ['Version' => (string) $account->version],
        );

        return self::SUCCESS;
    }
}
