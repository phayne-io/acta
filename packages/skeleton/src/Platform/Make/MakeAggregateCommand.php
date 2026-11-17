<?php

declare(strict_types=1);

namespace App\Platform\Make;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `bin/console make:aggregate Billing Invoice` — adds an event-sourced aggregate to a
 * context: the aggregate and its identifier, its first event, its repository (the port
 * and the adapter) and a given/when/then test.
 */
#[AsCommand('make:aggregate', 'Adds an event-sourced aggregate to a context, with its identifier, its repository and its test')]
final class MakeAggregateCommand extends MakeCommand
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('context', InputArgument::REQUIRED, 'Name of the context')
            ->addArgument('aggregate', InputArgument::REQUIRED, 'Name of the aggregate, Invoice for instance');
    }

    #[Override]
    protected function generate(InputInterface $input, ProjectFiles $files): array
    {
        $scaffold = self::withAggregate(
            $this->context($files, self::text($input->getArgument('context')) ?? ''),
            self::text($input->getArgument('aggregate')) ?? '',
        );
        $config = 'src/{{ Context }}/ConfigProvider.php';

        $scaffold->file('aggregate/Aggregate.php', 'src/{{ Context }}/Domain/{{ Aggregate }}.php');
        $scaffold->file('aggregate/AggregateId.php', 'src/{{ Context }}/Domain/{{ Aggregate }}Id.php');
        $scaffold->file('aggregate/Created.php', 'src/{{ Context }}/Domain/Event/{{ Aggregate }}Created.php');
        $scaffold->file('aggregate/Repository.php', 'src/{{ Context }}/Domain/{{ Aggregate }}Repository.php');
        $scaffold->file(
            'aggregate/EventSourcedRepository.php',
            'src/{{ Context }}/Infrastructure/Secondary/Persistence/EventSourced{{ Aggregate }}Repository.php',
        );
        $scaffold->file('aggregate/AggregateTest.php', 'tests/{{ Context }}/Domain/{{ Aggregate }}Test.php');

        $scaffold->line($config, 'events', '{{ Aggregate }}Created::class,', ['App\{{ Context }}\Domain\Event\{{ Aggregate }}Created']);
        $scaffold->line($config, 'aliases', '{{ Aggregate }}Repository::class => EventSourced{{ Aggregate }}Repository::class,', [
            'App\{{ Context }}\Domain\{{ Aggregate }}Repository',
            'App\{{ Context }}\Infrastructure\Secondary\Persistence\EventSourced{{ Aggregate }}Repository',
        ]);

        return [
            $scaffold->render('Model {{ Aggregate }} in src/{{ Context }}/Domain/{{ Aggregate }}.php: what creating one requires, and its state.'),
            $scaffold->render('Add a use case: bin/console make:command {{ Context }} <Command>'),
            $scaffold->render('Add a read model: bin/console make:query {{ Context }} Get{{ Aggregate }}'),
        ];
    }
}
