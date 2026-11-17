<?php

declare(strict_types=1);

namespace App\Banking;

use App\Banking\Application\Command\DepositMoneyHandler;
use App\Banking\Application\Command\OpenAccountHandler;
use App\Banking\Application\Command\WithdrawMoneyHandler;
use App\Banking\Application\Query\AccountDirectory;
use App\Banking\Application\Query\GetAccountHandler;
use App\Banking\Application\Query\ListAccountsHandler;
use App\Banking\Domain\AccountRepository;
use App\Banking\Domain\Event\AccountOpened;
use App\Banking\Domain\Event\MoneyDeposited;
use App\Banking\Domain\Event\MoneyWithdrawn;
use App\Banking\Infrastructure\Primary\Console\OpenAccountCommand;
use App\Banking\Infrastructure\Primary\Console\ShowAccountCommand;
use App\Banking\Infrastructure\Primary\Http\BusinessRuleMapper;
use App\Banking\Infrastructure\Primary\Http\DepositMoneyAction;
use App\Banking\Infrastructure\Primary\Http\GetAccountAction;
use App\Banking\Infrastructure\Primary\Http\ListAccountsAction;
use App\Banking\Infrastructure\Primary\Http\OpenAccountAction;
use App\Banking\Infrastructure\Primary\Http\WithdrawMoneyAction;
use App\Banking\Infrastructure\Primary\Webhook\PaymentsWebhookSource;
use App\Banking\Infrastructure\Secondary\Persistence\AccountDirectoryProjection;
use App\Banking\Infrastructure\Secondary\Persistence\EventSourcedAccountRepository;
use App\Banking\Infrastructure\Secondary\Persistence\InMemoryAccountDirectory;
use App\Banking\Infrastructure\Secondary\Persistence\PostgresAccountDirectory;
use App\Banking\Infrastructure\Secondary\Persistence\WritableAccountDirectory;
use App\Banking\Infrastructure\Secondary\Webhook\PublicEvents;
use App\Platform\Container\AdapterChoice;
use App\Platform\Env;

/**
 * Plugs the Banking context into the application.
 *
 * This file is the table of contents of the context: what it stores, which use cases it
 * offers and through which adapters. The `make:*` generators add their entries above
 * the `acta:` markers; keep them in place.
 */
final class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                // Ports and the adapters that implement them. Everything else is built from its constructor.
                'aliases' => [
                    AccountRepository::class => EventSourcedAccountRepository::class,
                    AccountDirectory::class => WritableAccountDirectory::class,
                    // acta:aliases
                ],
                'factories' => [
                    WritableAccountDirectory::class => new AdapterChoice(
                        memory: InMemoryAccountDirectory::class,
                        postgres: PostgresAccountDirectory::class,
                    ),
                    PaymentsWebhookSource::ID => PaymentsWebhookSource::create(...),
                    // acta:factories
                ],
            ],
            'acta' => [
                'events' => [
                    AccountOpened::class,
                    MoneyDeposited::class,
                    MoneyWithdrawn::class,
                    // acta:events
                ],
                'command_handlers' => [
                    OpenAccountHandler::class,
                    DepositMoneyHandler::class,
                    WithdrawMoneyHandler::class,
                    // acta:command-handlers
                ],
                'query_handlers' => [
                    GetAccountHandler::class,
                    ListAccountsHandler::class,
                    // acta:query-handlers
                ],
                'operations' => [
                    OpenAccountAction::class,
                    GetAccountAction::class,
                    ListAccountsAction::class,
                    DepositMoneyAction::class,
                    WithdrawMoneyAction::class,
                    // acta:operations
                ],
                'exception_mappers' => [
                    BusinessRuleMapper::class,
                    // acta:exception-mappers
                ],
                'projections' => [
                    AccountDirectoryProjection::NAME => AccountDirectoryProjection::class,
                    // acta:projections
                ],
                'schemas' => [
                    PostgresAccountDirectory::class,
                    // acta:schemas
                ],
                'webhook_sources' => [
                    PaymentsWebhookSource::ID,
                    // acta:webhook-sources
                ],
                'integration_events' => [
                    PublicEvents::class,
                    // acta:integration-events
                ],
                'console' => [
                    'commands' => [
                        'banking:account:open' => OpenAccountCommand::class,
                        'banking:account:show' => ShowAccountCommand::class,
                        // acta:console-commands
                    ],
                ],
                'webhooks' => [
                    'secrets' => [
                        // Shared with the payment provider, in the Standard Webhooks `whsec_…` form.
                        PaymentsWebhookSource::NAME => Env::string('WEBHOOK_PAYMENTS_SECRET'),
                        // acta:webhook-secrets
                    ],
                ],
            ],
        ];
    }
}
