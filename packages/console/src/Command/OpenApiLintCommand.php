<?php

declare(strict_types=1);

namespace Acta\Console\Command;

use Acta\Http\OpenApi\ContractLinter;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the OpenAPI contract of the application, for the continuous integration.
 *
 * Requires `acta/http`.
 */
#[AsCommand('openapi:lint', 'Checks the OpenAPI contract')]
final class OpenApiLintCommand extends Command
{
    /**
     * @param string|null $defaultFile contract checked when the command is given no file
     */
    public function __construct(
        private readonly ?string $defaultFile = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            'file',
            $this->defaultFile === null ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
            'Path of the contract: .yaml, .yml or .json',
            $this->defaultFile,
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('file');
        $file = is_string($argument) ? $argument : '';
        $issues = ContractLinter::lintFile($file);

        if ($issues === []) {
            $io->success(sprintf('%s is valid.', $file));

            return self::SUCCESS;
        }

        foreach ($issues as $issue) {
            $io->writeln(sprintf('<comment>#%s</comment>  %s', $issue->pointer, $issue->message));
        }

        $io->error(sprintf('%d issue%s found in %s.', count($issues), count($issues) === 1 ? '' : 's', $file));

        return self::FAILURE;
    }
}
