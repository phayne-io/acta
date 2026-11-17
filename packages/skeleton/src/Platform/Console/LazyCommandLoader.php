<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Container\Resolve;
use Override;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Loads console commands from the container, each one only when it is run.
 *
 * Listing the commands reads their `#[AsCommand]` attribute and builds nothing: no
 * database connection is opened and no identity provider is contacted to print the help.
 */
final readonly class LazyCommandLoader implements CommandLoaderInterface
{
    /**
     * @param array<array-key, string> $commands service identifiers, usually class names, keyed by command name
     */
    public function __construct(
        private ContainerInterface $container,
        private array $commands,
    ) {}

    #[Override]
    public function get(string $name): Command
    {
        $service = $this->commands[$name] ?? throw new CommandNotFoundException(sprintf('Command "%s" does not exist.', $name));
        $attribute = class_exists($service)
            ? (new ReflectionClass($service)->getAttributes(AsCommand::class)[0] ?? null)?->newInstance()
            : null;

        // A command that does not describe itself with the attribute has to be built to be listed.
        if ($attribute === null) {
            return Resolve::named($this->container, $service, Command::class);
        }

        // The attribute carries the aliases after the name, and an empty first name when the command is hidden.
        $names = explode('|', $attribute->name);
        $hidden = $names[0] === '';

        return new LazyCommand(
            $name,
            array_values(array_diff($names, ['', $name])),
            (string) $attribute->description,
            $hidden,
            fn(): Command => Resolve::named($this->container, $service, Command::class),
        );
    }

    #[Override]
    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getNames(): array
    {
        return array_map(strval(...), array_keys($this->commands));
    }
}
