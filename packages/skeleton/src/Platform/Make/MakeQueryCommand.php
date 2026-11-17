<?php

declare(strict_types=1);

namespace App\Platform\Make;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `bin/console make:query Billing GetInvoice` — adds a use case that reads, with all it
 * takes to answer it: the operation of the OpenAPI contract and its request handler,
 * the query and its handler, and the read model of the aggregate (its port, its
 * PostgreSQL and in-memory adapters, its table and the projection that fills it).
 *
 * The read model is shared by the queries about the same aggregate: a second query only
 * adds what is its own.
 */
#[AsCommand('make:query', 'Adds a use case that reads: query, handler, read model, projection, OpenAPI operation and HTTP adapter')]
final class MakeQueryCommand extends MakeCommand
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('context', InputArgument::REQUIRED, 'Name of the context')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the query: GetInvoice for instance')
            ->addOption('aggregate', 'a', InputOption::VALUE_REQUIRED, 'Aggregate the query is about; optional when the context has only one');
    }

    #[Override]
    protected function generate(InputInterface $input, ProjectFiles $files): array
    {
        $context = $this->context($files, self::text($input->getArgument('context')) ?? '');
        $name = Naming::studly(self::text($input->getArgument('name')) ?? '');
        $scaffold = $this->aggregate($files, $context, self::text($input->getOption('aggregate')))->with([
            'Name' => $name,
            'name' => Naming::camel($name),
            'name-kebab' => Naming::kebab($name),
        ]);
        $config = 'src/{{ Context }}/ConfigProvider.php';
        $persistence = 'src/{{ Context }}/Infrastructure/Secondary/Persistence/';
        $adapters = 'App\{{ Context }}\Infrastructure\Secondary\Persistence\\';
        $firstQuery = !$files->exists($scaffold->render('src/{{ Context }}/Application/Query/{{ Aggregate }}Directory.php'));

        // The read model of the aggregate, shared by its queries.
        $scaffold->file('query/View.php', 'src/{{ Context }}/Application/Query/{{ Aggregate }}View.php');
        $scaffold->file('query/Directory.php', 'src/{{ Context }}/Application/Query/{{ Aggregate }}Directory.php');
        $scaffold->file('query/WritableDirectory.php', $persistence . 'Writable{{ Aggregate }}Directory.php');
        $scaffold->file('query/InMemoryDirectory.php', $persistence . 'InMemory{{ Aggregate }}Directory.php');
        $scaffold->file('query/PostgresDirectory.php', $persistence . 'Postgres{{ Aggregate }}Directory.php');
        $scaffold->file('query/Projection.php', $persistence . '{{ Aggregate }}DirectoryProjection.php');
        $scaffold->file('query/ProjectionTest.php', 'tests/{{ Context }}/Infrastructure/{{ Aggregate }}DirectoryProjectionTest.php');

        if ($firstQuery) {
            $scaffold->line($config, 'aliases', '{{ Aggregate }}Directory::class => Writable{{ Aggregate }}Directory::class,', [
                'App\{{ Context }}\Application\Query\{{ Aggregate }}Directory',
                $adapters . 'Writable{{ Aggregate }}Directory',
            ]);
            $scaffold->line(
                $config,
                'factories',
                "Writable{{ Aggregate }}Directory::class => new AdapterChoice(\n"
                . "    memory: InMemory{{ Aggregate }}Directory::class,\n"
                . "    postgres: Postgres{{ Aggregate }}Directory::class,\n"
                . '),',
                [$adapters . 'InMemory{{ Aggregate }}Directory', $adapters . 'Postgres{{ Aggregate }}Directory', 'App\Platform\Container\AdapterChoice'],
            );
            $scaffold->line($config, 'projections', '{{ Aggregate }}DirectoryProjection::NAME => {{ Aggregate }}DirectoryProjection::class,', [
                $adapters . '{{ Aggregate }}DirectoryProjection',
            ]);
            $scaffold->line($config, 'schemas', 'Postgres{{ Aggregate }}Directory::class,');
            $scaffold->block('openapi/openapi.yaml', 'schemas', 'query/schema.yaml');
        }

        // The query itself.
        $scaffold->file('query/Query.php', 'src/{{ Context }}/Application/Query/{{ Name }}.php');
        $scaffold->file('query/Handler.php', 'src/{{ Context }}/Application/Query/{{ Name }}Handler.php');
        $scaffold->file('query/Action.php', 'src/{{ Context }}/Infrastructure/Primary/Http/{{ Name }}Action.php');

        $scaffold->line($config, 'query-handlers', '{{ Name }}Handler::class,', ['App\{{ Context }}\Application\Query\{{ Name }}Handler']);
        $scaffold->line($config, 'operations', '{{ Name }}Action::class,', ['App\{{ Context }}\Infrastructure\Primary\Http\{{ Name }}Action']);
        $scaffold->block('openapi/openapi.yaml', 'paths', 'query/operation.yaml');

        return [
            $scaffold->render('Give {{ Aggregate }}View what readers need, then the "{{ Aggregate }}" schema of openapi/openapi.yaml and the {{ table }} table the same.'),
            $scaffold->render('Teach {{ Aggregate }}DirectoryProjection the events that change it.'),
            'Create the table: bin/console es:migrate',
            $scaffold->render('Run the projection: bin/console projection:run {{ table }}'),
        ];
    }
}
