<?php

declare(strict_types=1);

namespace App\Platform\Make;

use InvalidArgumentException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `bin/console make:context Billing` — starts a business context.
 *
 * A context is a self-contained directory under `src/`, with its own `ConfigProvider`,
 * that shares nothing with the other contexts but the platform.
 */
#[AsCommand('make:context', 'Starts a business context: its directory, its ConfigProvider and its registration')]
final class MakeContextCommand extends MakeCommand
{
    private const array RESERVED = ['Platform', 'Shared'];

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('context', InputArgument::REQUIRED, 'Name of the context, Billing for instance');
    }

    #[Override]
    protected function generate(InputInterface $input, ProjectFiles $files): array
    {
        $scaffold = self::scaffold($files, self::text($input->getArgument('context')) ?? '');
        $context = $scaffold->value('Context');

        if (in_array($context, self::RESERVED, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a business context: the name is used by the skeleton.', $context));
        }

        $scaffold->file('context/ConfigProvider.php', 'src/{{ Context }}/ConfigProvider.php');
        $scaffold->file('context/RuleViolated.php', 'src/{{ Context }}/Domain/Exception/{{ Context }}RuleViolated.php');
        $scaffold->file('context/BusinessRuleMapper.php', 'src/{{ Context }}/Infrastructure/Primary/Http/BusinessRuleMapper.php');

        $scaffold->line('config/config.php', 'contexts', 'App\{{ Context }}\ConfigProvider::class,');
        $scaffold->line('openapi/openapi.yaml', 'tags', "- name: {{ Context }}\n  description: TODO Describe the {{ Context }} context.");

        return [
            sprintf('Give the context its first aggregate: bin/console make:aggregate %s <Aggregate>', $context),
        ];
    }
}
