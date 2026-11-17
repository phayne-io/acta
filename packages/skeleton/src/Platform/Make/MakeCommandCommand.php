<?php

declare(strict_types=1);

namespace App\Platform\Make;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `bin/console make:command Billing IssueInvoice --event InvoiceIssued` — adds a use
 * case that changes an aggregate, from the contract to the domain: the operation of the
 * OpenAPI contract, its request handler, a console command, the command, its handler,
 * the event and a test of the handler.
 */
#[AsCommand('make:command', 'Adds a use case that changes an aggregate: command, handler, event, OpenAPI operation, HTTP and console adapters')]
final class MakeCommandCommand extends MakeCommand
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('context', InputArgument::REQUIRED, 'Name of the context')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the command, in the imperative: IssueInvoice for instance')
            ->addOption('aggregate', 'a', InputOption::VALUE_REQUIRED, 'Aggregate the command acts on; optional when the context has only one')
            ->addOption('event', 'e', InputOption::VALUE_REQUIRED, 'Name of the event the command results in, in the past tense: InvoiceIssued for instance');
    }

    #[Override]
    protected function generate(InputInterface $input, ProjectFiles $files): array
    {
        $context = $this->context($files, self::text($input->getArgument('context')) ?? '');
        $name = Naming::studly(self::text($input->getArgument('name')) ?? '');
        $event = self::text($input->getOption('event'));
        $scaffold = $this->aggregate($files, $context, self::text($input->getOption('aggregate')))->with([
            'Name' => $name,
            'name' => Naming::camel($name),
            'name-kebab' => Naming::kebab($name),
            'name_words' => Naming::words($name),
        ]);
        $config = 'src/{{ Context }}/ConfigProvider.php';

        $scaffold->file('command/Command.php', 'src/{{ Context }}/Application/Command/{{ Name }}.php');
        $scaffold->file('command/Handler.php', 'src/{{ Context }}/Application/Command/{{ Name }}Handler.php');
        $scaffold->file('command/Action.php', 'src/{{ Context }}/Infrastructure/Primary/Http/{{ Name }}Action.php');
        $scaffold->file('command/ConsoleCommand.php', 'src/{{ Context }}/Infrastructure/Primary/Console/{{ Name }}Command.php');
        $scaffold->file('command/HandlerTest.php', 'tests/{{ Context }}/Application/{{ Name }}HandlerTest.php');

        $scaffold->line($config, 'command-handlers', '{{ Name }}Handler::class,', ['App\{{ Context }}\Application\Command\{{ Name }}Handler']);
        $scaffold->line($config, 'operations', '{{ Name }}Action::class,', ['App\{{ Context }}\Infrastructure\Primary\Http\{{ Name }}Action']);
        $scaffold->line($config, 'console-commands', "'{{ context }}:{{ name-kebab }}' => {{ Name }}Command::class,", [
            'App\{{ Context }}\Infrastructure\Primary\Console\{{ Name }}Command',
        ]);
        $scaffold->block('openapi/openapi.yaml', 'paths', 'command/operation.yaml');

        if ($event !== null) {
            $scaffold = $scaffold->with(['Event' => Naming::studly($event), 'event-kebab' => Naming::kebab(Naming::studly($event))]);
            $scaffold->file('command/Event.php', 'src/{{ Context }}/Domain/Event/{{ Event }}.php');
            $scaffold->line($config, 'events', '{{ Event }}::class,', ['App\{{ Context }}\Domain\Event\{{ Event }}']);
        }

        return [
            $scaffold->render('Write the rule in {{ Aggregate }}: a method that checks it and records ' . ($event === null ? 'an event' : '{{ Event }}') . ', then apply the event.'),
            $scaffold->render('Call that method from src/{{ Context }}/Application/Command/{{ Name }}Handler.php.'),
            $scaffold->render('Describe the request of the "{{ name }}" operation in openapi/openapi.yaml, and give {{ Name }} the same fields.'),
            $scaffold->render('Make tests/{{ Context }}/Application/{{ Name }}HandlerTest.php pass.'),
        ];
    }
}
