<?php

declare(strict_types=1);

namespace Acta\Console\Tests\Unit;

use Acta\Console\Command\AuthLoginCommand;
use Acta\Console\Command\AuthLogoutCommand;
use Acta\Console\Command\OpenApiLintCommand;
use Acta\Console\Command\ProjectionRebuildCommand;
use Acta\Console\Command\ProjectionRunCommand;
use Acta\Console\Command\ProjectionStatusCommand;
use Acta\Console\Command\ProjectionStopCommand;
use Acta\Console\Command\WebhookDeadLettersCommand;
use Acta\Console\Command\WebhookDeliverCommand;
use Acta\Console\Command\WebhookRetryCommand;
use Acta\Console\ConsoleMetadata;
use Acta\Console\ProjectionRegistry;
use Acta\Console\Schema\StatementsSchema;
use Acta\Console\Tests\Fixture\Banking\AccountOpened;
use Acta\Console\Tests\Fixture\Banking\PublicEvents;
use Acta\Console\Tests\Fixture\FakeSubscriber;
use Acta\Console\Tests\Fixture\FrozenClock;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionStatus;
use Acta\Oidc\Client\InMemoryTokenStorage;
use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\PublicClient;
use Acta\Oidc\Client\TokenSet;
use Acta\Oidc\Discovery\Discovery;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Testing\FakeIdentityProvider;
use Acta\Webhook\Outgoing\DeliveryStatus;
use Acta\Webhook\Outgoing\InMemoryDeliveryQueue;
use Acta\Webhook\Outgoing\InMemorySubscriptions;
use Acta\Webhook\Outgoing\RetryPolicy;
use Acta\Webhook\Outgoing\Subscription;
use Acta\Webhook\Outgoing\WebhookOutbox;
use Acta\Webhook\Outgoing\WebhookSender;
use ArrayObject;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
{
    private FrozenClock $clock;

    private InMemoryEventStore $eventStore;

    private EnvelopeFactory $envelopes;

    private ProjectionManager $projections;

    private ProjectionRegistry $registry;

    /** The command under test, for the closures that have to signal it while it runs. */
    private ProjectionRunCommand|WebhookDeliverCommand|null $signalled = null;

    /** The tester of the command under test, for the closures that read what it displayed so far. */
    private ?CommandTester $tester = null;

    /** @var ArrayObject<int, string> owners seen by the "owners" projection */
    private ArrayObject $owners;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->eventStore = new InMemoryEventStore();
        $this->envelopes = new EnvelopeFactory(new MessageTypeMap(), $this->clock);
        $this->projections = new ProjectionManager($this->eventStore, new InMemoryProjectionStore(), $this->eventStore, $this->envelopes);
        $this->owners = $owners = new ArrayObject();
        $this->registry = new ProjectionRegistry()
            ->add('owners', fn() => $this->projections->projection('owners')->fromAll()->when([
                AccountOpened::class => static function (array $state, AccountOpened $event) use ($owners): array {
                    $owners->append($event->owner);

                    return ['count' => ($state['count'] ?? 0) + 1];
                },
            ]))
            ->add('audit', fn() => $this->projections->projection('audit')->fromAll()->whenAny(static fn() => null));
    }

    public function testAProjectionRunsUntilItHasCaughtUp(): void
    {
        $this->open('Ada', 'Grace');

        $tester = $this->execute(new ProjectionRunCommand($this->registry), ['name' => 'owners', '--once' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Running projection owners until it has caught up.', $tester->getDisplay());
        self::assertStringContainsString('Projection owners stopped at position 0:2.', $tester->getDisplay());
        self::assertSame(['Ada', 'Grace'], $this->owners->getArrayCopy());
        self::assertSame(['count' => 2], $this->projections->state('owners'));
    }

    public function testAStopSignalEndsARunningProjectionCleanly(): void
    {
        $this->open('Ada');
        $registry = new ProjectionRegistry()->add('owners', function () {
            // Without --once the projection would wait for events forever: handling
            // the first event plays the operator pressing Ctrl+C.
            return $this->projections->projection('owners')->fromAll()->whenAny(function (): void {
                $this->signalled?->handleSignal(15);
            });
        });
        $this->signalled = $command = new ProjectionRunCommand($registry);

        $tester = $this->execute($command, ['name' => 'owners']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('stopped at position 0:1', $tester->getDisplay());
        self::assertFalse($command->handleSignal(2));
        // Nothing without the pcntl extension; with it, SIGINT, SIGTERM and SIGQUIT, which
        // is the signal a container built on the PHP-FPM image is stopped with.
        self::assertContains($command->getSubscribedSignals(), [[], [2, 15, 3]]);
    }

    public function testAnUnknownProjectionIsAnError(): void
    {
        foreach ([new ProjectionRunCommand($this->registry), new ProjectionRebuildCommand($this->registry)] as $command) {
            $tester = $this->execute($command, ['name' => 'nope']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('No projection is named "nope". Known projections: owners, audit.', self::flat($tester));
        }
    }

    public function testAProjectionIsRebuiltFromTheFirstEvent(): void
    {
        $this->open('Ada', 'Grace');
        $this->execute(new ProjectionRunCommand($this->registry), ['name' => 'owners', '--once' => true]);
        $this->open('Edsger');

        $tester = $this->execute(new ProjectionRebuildCommand($this->registry), ['name' => 'owners', '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Projection owners was rebuilt up to position 0:3.', $tester->getDisplay());
        // Two events the first time, then all three again.
        self::assertSame(['Ada', 'Grace', 'Ada', 'Grace', 'Edsger'], $this->owners->getArrayCopy());
        self::assertSame(['count' => 3], $this->projections->state('owners'));
    }

    public function testARebuildAsksForConfirmation(): void
    {
        $this->open('Ada');
        $command = new ProjectionRebuildCommand($this->registry);

        $declined = new CommandTester($command);
        $declined->setInputs(['no']);
        $declined->execute(['name' => 'owners']);

        self::assertSame(Command::FAILURE, $declined->getStatusCode());
        self::assertStringContainsString('Nothing was changed.', $declined->getDisplay());
        self::assertSame([], $this->owners->getArrayCopy());

        $accepted = new CommandTester($command);
        $accepted->setInputs(['yes']);
        $accepted->execute(['name' => 'owners']);

        self::assertSame(Command::SUCCESS, $accepted->getStatusCode());
        self::assertSame(['Ada'], $this->owners->getArrayCopy());

        // Without a terminal to answer, nothing is rebuilt.
        $unattended = new CommandTester($command);
        $unattended->execute(['name' => 'owners'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $unattended->getStatusCode());
        self::assertSame(['Ada'], $this->owners->getArrayCopy());
    }

    public function testProjectionsAreListedWithTheirStatusAndPosition(): void
    {
        $empty = $this->execute(new ProjectionStatusCommand($this->projections));

        self::assertStringContainsString('No projection yet.', $empty->getDisplay());

        $this->open('Ada');
        $this->execute(new ProjectionRunCommand($this->registry), ['name' => 'owners', '--once' => true]);

        $display = $this->execute(new ProjectionStatusCommand($this->projections, $this->registry))->getDisplay();

        self::assertMatchesRegularExpression('/audit\s+never run\s+-/', $display);
        self::assertMatchesRegularExpression('/owners\s+idle\s+0:1/', $display);
        self::assertStringNotContainsString('audit', $this->execute(new ProjectionStatusCommand($this->projections))->getDisplay());
    }

    public function testAProjectionIsAskedToStopFromAnotherProcess(): void
    {
        $this->execute(new ProjectionRunCommand($this->registry), ['name' => 'owners', '--once' => true]);

        $tester = $this->execute(new ProjectionStopCommand($this->projections), ['name' => 'owners']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(ProjectionStatus::Stopping, $this->projections->status('owners'));

        $unknown = $this->execute(new ProjectionStopCommand($this->projections), ['name' => 'nope']);

        self::assertSame(Command::FAILURE, $unknown->getStatusCode());
    }

    public function testDueWebhooksAreSent(): void
    {
        [$sender, $queue, $http] = $this->webhooks('Ada', 'Grace', 'Edsger');
        $http->answers = [204, 500];

        $tester = $this->execute(new WebhookDeliverCommand($sender), ['--limit' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("1 delivered, 1 to retry, 0 given up\n", $tester->getDisplay(true));
        self::assertCount(2, $http->received);
        self::assertSame([DeliveryStatus::Delivered, DeliveryStatus::Pending, DeliveryStatus::Pending], array_column($queue->all(), 'status'));

        self::assertSame("1 delivered, 0 to retry, 0 given up\n", $this->execute(new WebhookDeliverCommand($sender))->getDisplay(true));
        self::assertSame("0 delivered, 0 to retry, 0 given up\n", $this->execute(new WebhookDeliverCommand($sender), ['--limit' => 'many'])->getDisplay(true));
    }

    public function testWatchingKeepsSendingUntilASignalStopsIt(): void
    {
        [$sender, , $http] = $this->webhooks('Ada', 'Grace', 'Edsger');
        $waits = [];
        $this->signalled = $command = new WebhookDeliverCommand($sender, function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
            $this->clock->advance("+{$seconds} seconds");

            if (count($waits) === 2) {
                $this->signalled?->handleSignal(15);
            }
        });

        $tester = $this->execute($command, ['--watch' => true, '--limit' => '2', '--interval' => '7']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        // A full batch is followed at once by the next one; an idle loop prints nothing.
        self::assertSame("2 delivered, 0 to retry, 0 given up\n1 delivered, 0 to retry, 0 given up\n", $tester->getDisplay(true));
        self::assertSame([7, 7], $waits);
        self::assertCount(3, $http->received);
        self::assertContains($command->getSubscribedSignals(), [[], [2, 15, 3]]);
    }

    public function testDeadLettersAreListedAndSentAgain(): void
    {
        [$sender, $queue, $http] = $this->webhooks('Ada', 'Grace');
        $http->answers = [410, 410];
        $sender->deliverDue();
        $ids = array_column($queue->all(), 'id');

        $list = $this->execute(new WebhookDeadLettersCommand($queue), ['--limit' => '10']);

        self::assertSame(Command::SUCCESS, $list->getStatusCode());
        self::assertStringContainsString($ids[0], $list->getDisplay());
        self::assertStringContainsString('account.opened', $list->getDisplay());
        self::assertStringContainsString('The subscriber answered with status 410.', self::flat($list));
        self::assertStringContainsString('webhook:retry <delivery>', $list->getDisplay());

        $retry = $this->execute(new WebhookRetryCommand($queue, $this->clock), ['delivery' => [$ids[0], 'msg_unknown']]);

        self::assertSame(Command::FAILURE, $retry->getStatusCode());
        self::assertStringContainsString($ids[0] . ' is due again', $retry->getDisplay());
        self::assertStringContainsString('Not a dead letter: msg_unknown.', self::flat($retry));

        self::assertSame(Command::SUCCESS, $this->execute(new WebhookRetryCommand($queue, $this->clock), ['delivery' => [$ids[1]]])->getStatusCode());
        self::assertSame("2 delivered, 0 to retry, 0 given up\n", $this->execute(new WebhookDeliverCommand($sender))->getDisplay(true));
        self::assertStringContainsString('No dead letter.', $this->execute(new WebhookDeadLettersCommand($queue))->getDisplay());
    }

    public function testTheContractIsLinted(): void
    {
        $valid = $this->execute(new OpenApiLintCommand(), ['file' => __DIR__ . '/../Fixture/webhooks.yaml']);

        self::assertSame(Command::SUCCESS, $valid->getStatusCode());
        // The path is not asserted: the console wraps it wherever its length demands.
        self::assertStringContainsString(' is valid.', self::flat($valid));

        $file = sys_get_temp_dir() . '/acta-lint-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Broken', 'version' => '1'],
            'paths' => ['/things' => ['get' => ['operationId' => 'listThings'], 'post' => ['operationId' => 'createThing', 'responses' => ['201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]]]]]]],
        ]));

        try {
            // The file may also be the default of the command, as an application configures it.
            $broken = $this->execute(new OpenApiLintCommand($file));
        } finally {
            unlink($file);
        }

        self::assertSame(Command::FAILURE, $broken->getStatusCode());
        self::assertStringContainsString('#/paths/~1things/get/responses  Operation "listThings" declares no response.', $broken->getDisplay());
        self::assertStringContainsString('Reference "#/components/schemas/Thing" leads nowhere.', $broken->getDisplay());
        self::assertStringContainsString('3 issues found', $broken->getDisplay());

        $missing = $this->execute(new OpenApiLintCommand(), ['file' => '/nowhere/openapi.yaml']);

        self::assertSame(Command::FAILURE, $missing->getStatusCode());
        self::assertStringContainsString('1 issue found', $missing->getDisplay());
    }

    public function testTheUserLogsInFromTheTerminal(): void
    {
        [$idp, $client] = $this->identityProvider();
        $storage = new InMemoryTokenStorage();
        $command = new AuthLoginCommand($client, $storage, ['openid', 'accounts:read'], function () use ($idp): void {
            // Instead of waiting, the user reads the code on the terminal and approves in a browser.
            preg_match('/enter the code ([0-9A-F-]+)/', (string) $this->tester?->getDisplay(), $matches);
            $idp->approveDevice($matches[1] ?? '', 'ada');
        });
        $this->tester = $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Open ' . $idp->issuer . '/activate', $tester->getDisplay());
        self::assertStringContainsString('or open directly ' . $idp->issuer . '/activate?user_code=', $tester->getDisplay());
        self::assertStringContainsString('Logged in as ada.lovelace.', $tester->getDisplay());
        self::assertSame(['openid', 'accounts:read'], $storage->load()?->scopes);

        $logout = $this->execute(new AuthLogoutCommand($storage));

        self::assertSame(Command::SUCCESS, $logout->getStatusCode());
        self::assertStringContainsString('Logged out.', $logout->getDisplay());
        self::assertNull($storage->load());
    }

    public function testScopesCanBeChosenWhenLoggingIn(): void
    {
        [$idp, $client] = $this->identityProvider();
        $storage = new InMemoryTokenStorage();
        $command = new AuthLoginCommand($client, $storage, ['openid'], function () use ($idp): void {
            preg_match('/enter the code ([0-9A-F-]+)/', (string) $this->tester?->getDisplay(), $matches);
            $idp->approveDevice($matches[1] ?? '', 'ada');
        });
        $this->tester = $tester = new CommandTester($command);

        $tester->execute(['--scope' => ['accounts:write']]);

        // Without the openid scope there is no ID token to greet the user by name.
        self::assertStringContainsString('Logged in.', $tester->getDisplay());
        self::assertSame(['accounts:write'], $storage->load()?->scopes);
    }

    public function testAFailedLoginStoresNothing(): void
    {
        [$idp, $client] = $this->identityProvider();
        $storage = new InMemoryTokenStorage(new TokenSet('previous'));
        $command = new AuthLoginCommand($client, $storage, wait: function () use ($idp): void {
            preg_match('/enter the code ([0-9A-F-]+)/', (string) $this->tester?->getDisplay(), $matches);
            $idp->denyDevice($matches[1] ?? '');
        });
        $this->tester = $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Login failed: access_denied: The user refused.', self::flat($tester));
        self::assertSame('previous', $storage->load()?->accessToken);

        $idp->available = false;
        $outage = $this->execute(new AuthLoginCommand($client, $storage));

        self::assertSame(Command::FAILURE, $outage->getStatusCode());
        self::assertStringContainsString('Login failed:', $outage->getDisplay());
    }

    public function testConsoleMessagesAreTraceable(): void
    {
        $metadata = ConsoleMetadata::create(more: ['ticket' => 'OPS-42', 'channel' => 'overridden']);

        self::assertSame('cli', $metadata->get('channel'));
        self::assertSame('OPS-42', $metadata->get('ticket'));
        self::assertSame(get_current_user(), $metadata->get('actor'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $metadata->get(Metadata::CORRELATION_ID));
        self::assertSame('deploy-bot', ConsoleMetadata::create('deploy-bot')->get('actor'));
        self::assertNotSame($metadata->get(Metadata::CORRELATION_ID), ConsoleMetadata::create()->get(Metadata::CORRELATION_ID));
    }

    public function testASchemaIsNamedStatements(): void
    {
        $schema = new StatementsSchema('event store', ['CREATE TABLE a ()', 'CREATE INDEX b ON a ()']);

        self::assertSame('event store', $schema->name());
        self::assertCount(2, $schema->statements());
        self::assertTrue($this->registry->has('owners'));
        self::assertFalse($this->registry->has('nope'));
    }

    /**
     * @return array{FakeIdentityProvider, OAuthClient}
     */
    private function identityProvider(): array
    {
        $idp = new FakeIdentityProvider(new ResponseFactory(), new StreamFactory(), clock: $this->clock);
        $idp->registerClient('bank-cli');
        $idp->setUser('ada', ['preferred_username' => 'ada.lovelace']);
        $transport = new Transport($idp, new RequestFactory(), new StreamFactory());

        return [$idp, new OAuthClient('bank-cli', new PublicClient(), new Discovery($idp->issuer, $transport), $transport, clock: $this->clock)];
    }

    private function open(string ...$owners): void
    {
        foreach ($owners as $owner) {
            $this->eventStore->append(
                StreamName::for('account', strtolower($owner)),
                ExpectedVersion::noStream(),
                $this->envelopes->wrap(new AccountOpened(strtolower($owner), $owner)),
            );
        }
    }

    /**
     * Queues one webhook per account opened, for one subscriber.
     *
     * @return array{WebhookSender, InMemoryDeliveryQueue, FakeSubscriber}
     */
    private function webhooks(string ...$owners): array
    {
        $subscriptions = new InMemorySubscriptions(new Subscription('crm', 'https://crm.example.test/hooks', 'secret'));
        $queue = new InMemoryDeliveryQueue();
        $http = new FakeSubscriber();
        $this->open(...$owners);
        new WebhookOutbox(new PublicEvents(), $subscriptions, $queue, clock: $this->clock)->projection($this->projections)->run(keepRunning: false);

        return [
            new WebhookSender($queue, $subscriptions, $http, new RequestFactory(), new StreamFactory(), new RetryPolicy(jitter: 0.0), $this->clock),
            $queue,
            $http,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(Command $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * Returns what a command displayed on one line: long messages are wrapped by the console.
     */
    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
