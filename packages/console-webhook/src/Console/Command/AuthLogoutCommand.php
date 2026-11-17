<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Oidc\Client\TokenStorage;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Forgets the tokens stored by `auth:login`.
 *
 * The session at the identity provider is not ended: the tokens are only removed from
 * this machine.
 *
 * Requires `acta/oidc`.
 */
#[AsCommand('auth:logout', 'Forgets the stored tokens')]
final class AuthLogoutCommand extends Command
{
    public function __construct(
        private readonly TokenStorage $storage,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->clear();
        new SymfonyStyle($input, $output)->success('Logged out.');

        return self::SUCCESS;
    }
}
