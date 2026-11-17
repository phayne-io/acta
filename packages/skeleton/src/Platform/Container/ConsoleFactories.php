<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Acta\Console\Command\AuthLoginCommand;
use Acta\Console\Command\AuthLogoutCommand;
use Acta\Console\Command\MigrateCommand;
use Acta\Console\Command\OpenApiLintCommand;
use Acta\Console\Command\ProjectionRebuildCommand;
use Acta\Console\Command\ProjectionRunCommand;
use Acta\Console\Command\ProjectionStatusCommand;
use Acta\Console\Command\ProjectionStopCommand;
use Acta\Console\Command\WebhookDeadLettersCommand;
use Acta\Console\Command\WebhookDeliverCommand;
use Acta\Console\Command\WebhookRetryCommand;
use Acta\Console\ProjectionRegistry;
use Acta\Console\Schema\Schema;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\TokenStorage;
use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\WebhookSender;
use App\Platform\Console\LazyCommandLoader;
use App\Platform\Make\ProjectFiles;
use App\Platform\Settings;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;

/**
 * Builds the console primary adapter: `bin/console`.
 *
 * Commands are declared under `acta.console.commands`, keyed by name, and created on
 * first use: listing the commands opens no database connection.
 */
final class ConsoleFactories
{
    public static function application(ContainerInterface $container): Application
    {
        $settings = Settings::of($container);
        $application = new Application($settings->string('acta.name', 'Acta application'), $settings->string('acta.version', 'dev'));
        $application->setCommandLoader(new LazyCommandLoader($container, $settings->strings('acta.console.commands')));

        return $application;
    }

    public static function migrate(ContainerInterface $container): MigrateCommand
    {
        return new MigrateCommand(
            Resolve::from($container, PDO::class),
            ...Resolve::all($container, array_unique(Settings::of($container)->strings('acta.schemas')), Schema::class),
        );
    }

    public static function projectionRun(ContainerInterface $container): ProjectionRunCommand
    {
        return new ProjectionRunCommand(Resolve::from($container, ProjectionRegistry::class));
    }

    public static function projectionRebuild(ContainerInterface $container): ProjectionRebuildCommand
    {
        return new ProjectionRebuildCommand(Resolve::from($container, ProjectionRegistry::class));
    }

    public static function projectionStatus(ContainerInterface $container): ProjectionStatusCommand
    {
        return new ProjectionStatusCommand(
            Resolve::from($container, ProjectionManager::class),
            Resolve::from($container, ProjectionRegistry::class),
        );
    }

    public static function projectionStop(ContainerInterface $container): ProjectionStopCommand
    {
        return new ProjectionStopCommand(Resolve::from($container, ProjectionManager::class));
    }

    public static function webhookDeliver(ContainerInterface $container): WebhookDeliverCommand
    {
        return new WebhookDeliverCommand(Resolve::from($container, WebhookSender::class));
    }

    public static function webhookDeadLetters(ContainerInterface $container): WebhookDeadLettersCommand
    {
        return new WebhookDeadLettersCommand(Resolve::from($container, DeliveryQueue::class));
    }

    public static function webhookRetry(ContainerInterface $container): WebhookRetryCommand
    {
        return new WebhookRetryCommand(Resolve::from($container, DeliveryQueue::class), Resolve::from($container, ClockInterface::class));
    }

    public static function openApiLint(ContainerInterface $container): OpenApiLintCommand
    {
        return new OpenApiLintCommand(Settings::of($container)->path('acta.http.contract'));
    }

    public static function authLogin(ContainerInterface $container): AuthLoginCommand
    {
        return new AuthLoginCommand(
            Resolve::from($container, OAuthClient::class),
            Resolve::from($container, TokenStorage::class),
            array_values(Settings::of($container)->strings('acta.oidc.scopes')),
        );
    }

    public static function authLogout(ContainerInterface $container): AuthLogoutCommand
    {
        return new AuthLogoutCommand(Resolve::from($container, TokenStorage::class));
    }

    /**
     * What the `make:*` generators write to: the project itself.
     */
    public static function projectFiles(ContainerInterface $container): ProjectFiles
    {
        return new ProjectFiles(Settings::of($container)->string('acta.root'));
    }
}
