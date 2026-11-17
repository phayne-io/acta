<?php

declare(strict_types=1);

namespace App\Platform\Make;

use InvalidArgumentException;
use Override;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the `make:*` generators share: the vocabulary of a context and the report of
 * what was written.
 *
 * A generator writes a starting point, not a finished feature: documented classes in
 * the right layer, registered where they must be, and a test that stays red until the
 * business rule is written.
 */
abstract class MakeCommand extends Command
{
    public function __construct(
        private readonly ProjectFiles $files,
    ) {
        parent::__construct();
    }

    /**
     * Writes the files.
     *
     * @return list<string> what the developer should do next
     *
     * @throws InvalidArgumentException when the input names something that cannot be generated
     * @throws RuntimeException         when a file cannot be read or written
     */
    abstract protected function generate(InputInterface $input, ProjectFiles $files): array;

    #[Override]
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $next = $this->generate($input, $this->files);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach (['created' => $this->files->created, 'updated' => $this->files->updated, 'kept as it is' => $this->files->kept] as $what => $files) {
            foreach ($files as $file) {
                $io->writeln(sprintf(' <info>%-13s</info> %s', $what, $file));
            }
        }

        foreach ($this->files->manual as $manual) {
            $io->warning($manual);
        }

        if ($next !== []) {
            $io->section('Next');
            $io->listing($next);
        }

        return $this->files->manual === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Returns the scaffold of an existing context.
     *
     * @throws InvalidArgumentException when the context does not exist
     */
    final protected function context(ProjectFiles $files, string $name): Scaffold
    {
        $scaffold = self::scaffold($files, $name);

        return $files->exists($scaffold->render('src/{{ Context }}/ConfigProvider.php'))
            ? $scaffold
            : throw new InvalidArgumentException(sprintf(
                'There is no "%1$s" context. Create it with: bin/console make:context %1$s',
                $scaffold->value('Context'),
            ));
    }

    /**
     * Returns the scaffold of a context, existing or not.
     */
    final protected static function scaffold(ProjectFiles $files, string $context): Scaffold
    {
        $context = Naming::studly($context);

        return new Scaffold($files, [
            'Context' => $context,
            'context' => Naming::kebab($context),
            'context_snake' => Naming::snake($context),
        ]);
    }

    /**
     * Adds the vocabulary of an aggregate of the context to a scaffold.
     *
     * @param string|null $aggregate name of the aggregate; the only one of the context when `null`
     *
     * @throws InvalidArgumentException when the aggregate does not exist or cannot be guessed
     */
    final protected function aggregate(ProjectFiles $files, Scaffold $context, ?string $aggregate): Scaffold
    {
        $domain = $context->render('src/{{ Context }}/Domain');

        if ($aggregate === null) {
            $candidates = array_map(
                static fn(string $file): string => basename($file, 'Id.php'),
                glob($files->root . '/' . $domain . '/*Id.php') ?: [],
            );
            $aggregate = count($candidates) === 1 ? $candidates[0] : throw new InvalidArgumentException(sprintf(
                'The %s context has %s: say which one with --aggregate.',
                $context->value('Context'),
                $candidates === [] ? 'no aggregate yet (bin/console make:aggregate)' : 'several aggregates (' . implode(', ', $candidates) . ')',
            ));
        }

        $scaffold = self::withAggregate($context, $aggregate);

        return $files->exists($scaffold->render('src/{{ Context }}/Domain/{{ Aggregate }}.php'))
            ? $scaffold
            : throw new InvalidArgumentException(sprintf(
                'The %1$s context has no "%2$s" aggregate. Create it with: bin/console make:aggregate %1$s %2$s',
                $context->value('Context'),
                $scaffold->value('Aggregate'),
            ));
    }

    /**
     * Adds the vocabulary of an aggregate, existing or not, to a scaffold.
     */
    final protected static function withAggregate(Scaffold $context, string $aggregate): Scaffold
    {
        $aggregate = Naming::studly($aggregate);

        return $context->with([
            'Aggregate' => $aggregate,
            'aggregate' => Naming::camel($aggregate),
            'aggregate-kebab' => Naming::kebab($aggregate),
            'aggregate_words' => Naming::words($aggregate),
            'aggregates' => Naming::kebab(Naming::plural($aggregate)),
            'table' => $context->value('context_snake') . '_' . Naming::snake(Naming::plural($aggregate)),
            'category' => $context->value('context_snake') . '_' . Naming::snake($aggregate),
        ]);
    }

    /**
     * Reads a string argument or option, since the console types them loosely.
     */
    final protected static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
