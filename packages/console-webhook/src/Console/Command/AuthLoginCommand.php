<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\TokenStorage;
use Acta\Oidc\Exception\OidcException;
use Closure;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Logs the user of the console in, with the device authorization grant: the user
 * approves in a browser, on this machine or another one, and the tokens are stored
 * for the commands that call protected services.
 *
 * Requires `acta/oidc`.
 */
#[AsCommand('auth:login', 'Logs in with the identity provider')]
final class AuthLoginCommand extends Command
{
    /**
     * @param list<string>        $scopes scopes asked for by default
     * @param Closure(int): mixed $wait   pauses for the given number of seconds between two polls
     */
    public function __construct(
        private readonly OAuthClient $client,
        private readonly TokenStorage $storage,
        private readonly array $scopes = ['openid'],
        private readonly Closure $wait = sleep(...),
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('scope', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Scopes to ask for, instead of the default ones');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requested = $input->getOption('scope');
        $scopes = array_values(array_filter(is_array($requested) ? $requested : [], is_string(...)));

        try {
            $device = $this->client->requestDeviceAuthorization($scopes === [] ? $this->scopes : $scopes);

            $io->writeln(sprintf('Open <info>%s</info>', $device->verificationUri));
            $io->writeln(sprintf('and enter the code <info>%s</info>', $device->userCode));

            if ($device->verificationUriComplete !== null) {
                $io->writeln(sprintf('or open directly %s', $device->verificationUriComplete));
            }

            $io->writeln('Waiting for you to approve…');

            $tokens = $this->client->pollDeviceToken($device, $this->wait);
        } catch (OidcException $exception) {
            $io->error('Login failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->storage->save($tokens);
        $claims = $tokens->idTokenClaims;
        $who = $claims?->string('preferred_username') ?? $claims?->string('email') ?? $claims?->string('sub');

        $io->success($who === null ? 'Logged in.' : sprintf('Logged in as %s.', $who));

        return self::SUCCESS;
    }
}
