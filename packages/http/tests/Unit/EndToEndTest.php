<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit;

use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\Middleware\TransactionMiddleware;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\SystemClock;
use Acta\Http\EventSourcing\EventSourcingExceptionMapper;
use Acta\Http\EventSourcing\IfMatch;
use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\EventSourcing\MetadataFactory;
use Acta\Http\Exception\HttpException;
use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Handler\OperationHandlers;
use Acta\Http\Idempotency\IdempotencyMiddleware;
use Acta\Http\Idempotency\InMemoryIdempotencyStore;
use Acta\Http\Pipeline;
use Acta\Http\Problem\ExceptionMapper;
use Acta\Http\Problem\ProblemDetailsMiddleware;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Security\SecurityMiddleware;
use Acta\Http\Testing\ContractAssertions;
use Acta\Http\Tests\Fixture\Banking\Account;
use Acta\Http\Tests\Fixture\Banking\AccountId;
use Acta\Http\Tests\Fixture\Banking\AccountOpened;
use Acta\Http\Tests\Fixture\Banking\DepositMoney;
use Acta\Http\Tests\Fixture\Banking\MoneyDeposited;
use Acta\Http\Tests\Fixture\Banking\OpenAccount;
use Acta\Http\Tests\Fixture\RecordingLogger;
use Acta\Http\Tests\Fixture\TokenAuthenticator;
use Acta\Http\Tests\HttpTestCase;
use Acta\Http\Trace\CorrelationMiddleware;
use Acta\Http\Validation\Input;
use Acta\Http\Validation\RequestValidationMiddleware;
use Acta\Http\Validation\RequestValidator;
use Acta\Http\Validation\ResponseValidationMiddleware;
use Acta\Http\Validation\ResponseValidator;
use DomainException;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use Money\Currency;
use Money\Money;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The whole pipeline in front of an event-sourced application, as an application wires it.
 */
final class EndToEndTest extends HttpTestCase
{
    private const array AUTH = ['Authorization' => 'Bearer writer'];

    private InMemoryEventStore $eventStore;

    /** @var AggregateRepository<Account> */
    private AggregateRepository $accounts;

    private RecordingLogger $logger;

    private Pipeline $api;

    #[Override]
    protected function setUp(): void
    {
        $contract = self::contract();
        $types = new MessageTypeMap(AccountOpened::class, MoneyDeposited::class);
        $causation = new CausationMiddleware('actor');
        $envelopes = new EnvelopeFactory($types, new SystemClock(), $causation);
        $this->eventStore = new InMemoryEventStore(new MessageSerializer($types));
        $this->accounts = $accounts = new AggregateRepository(Account::class, 'account', $this->eventStore, $envelopes);
        $this->logger = new RecordingLogger();

        $commands = new DefaultCommandBus(
            new HandlerMap()
                ->on(OpenAccount::class, static fn(OpenAccount $command) => $accounts->save(
                    Account::open($command->accountId, $command->owner, new Currency($command->currency)),
                ))
                ->on(DepositMoney::class, static function (DepositMoney $command) use ($accounts): void {
                    $account = $accounts->get($command->accountId);
                    $account->deposit($command->money);
                    $accounts->save($account);
                }),
            $envelopes,
            $causation,
            new TransactionMiddleware($this->eventStore),
        );

        $businessRules = new class implements ExceptionMapper {
            #[Override]
            public function map(Throwable $exception): ?HttpException
            {
                return $exception instanceof DomainException
                    ? new HttpException(422, $exception->getMessage(), title: 'Business rule violated', previous: $exception)
                    : null;
            }
        };

        $this->api = new Pipeline(
            new OperationDispatcher($this->handlers($commands)),
            new CorrelationMiddleware(),
            new ProblemDetailsMiddleware(self::responder(), [new EventSourcingExceptionMapper(), $businessRules], $this->logger),
            new RoutingMiddleware(new Router($contract)),
            new SecurityMiddleware($contract, [
                'bearer' => new TokenAuthenticator('Authorization', ['Bearer writer' => ['accounts:read', 'accounts:write']]),
                'apiKey' => new TokenAuthenticator('X-Api-Key', []),
            ]),
            new RequestValidationMiddleware(new RequestValidator($contract)),
            new IdempotencyMiddleware(new InMemoryIdempotencyStore(), new ResponseFactory(), new StreamFactory()),
            new ResponseValidationMiddleware(new ResponseValidator($contract)),
        );
    }

    public function testAnAccountIsOpenedThroughTheApi(): void
    {
        $response = $this->api->handle(self::request(
            'POST',
            '/accounts',
            [...self::AUTH, 'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
            ['owner' => 'Ada', 'currency' => 'USD'],
        ));
        $account = self::json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/accounts/' . $account['id'], $response->getHeaderLine('Location'));
        self::assertSame('"1"', $response->getHeaderLine('ETag'));
        self::assertStringStartsWith('00-0af7651916cd43dd8448eb211c80319c-', $response->getHeaderLine('traceparent'));
        self::assertSame(['owner' => 'Ada', 'balance' => ['amount' => '0', 'currency' => 'USD'], 'version' => 1], array_diff_key($account, ['id' => true]));

        // The event carries the trace of the request and who made it.
        $recorded = [...$this->eventStore->load($this->accounts->streamName((string) $account['id']))];

        self::assertCount(1, $recorded);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $recorded[0]->metadata->get(Metadata::CORRELATION_ID));
        self::assertSame('Bearer writer', $recorded[0]->metadata->get('actor'));
        self::assertSame([], $this->logger->records);
    }

    public function testAnAccountIsReadWithItsVersion(): void
    {
        $id = $this->open();
        $response = $this->api->handle(self::request('GET', '/accounts/' . $id, [...self::AUTH, 'X-Flags' => '1']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"1"', $response->getHeaderLine('ETag'));
        self::assertSame($id, self::json($response)['id']);
    }

    public function testADepositMovesTheAccountToItsNextVersion(): void
    {
        $id = $this->open();
        $deposit = static fn(array $headers, string $amount = '12000', string $currency = 'EUR'): ServerRequestInterface => self::request(
            'POST',
            "/accounts/{$id}/deposits",
            [...self::AUTH, ...$headers],
            ['money' => ['amount' => $amount, 'currency' => $currency]],
        );

        $accepted = $this->api->handle($deposit(['If-Match' => '"1"']));

        self::assertSame(202, $accepted->getStatusCode());
        self::assertSame(['accountId' => $id, 'version' => 2], self::json($accepted));
        self::assertEquals(Money::EUR(12000), $this->balance($id));

        // The client still believes the account is at version 1.
        $stale = $this->api->handle($deposit(['If-Match' => '"1"']));

        self::assertSame(412, $stale->getStatusCode());
        self::assertSame('The resource is at version 2, not at version 1. Read it again, then retry.', self::json($stale)['detail']);

        // A rule of the domain is a 422 with its own title.
        $refused = $this->api->handle($deposit([], '500', 'USD'));

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('Business rule violated', self::json($refused)['title']);
        self::assertSame('This account holds EUR.', self::json($refused)['detail']);
        self::assertEquals(Money::EUR(12000), $this->balance($id));
    }

    public function testARequestThatBreaksTheContractNeverReachesTheApplication(): void
    {
        $response = $this->api->handle(self::request('POST', '/accounts', self::AUTH, ['owner' => '', 'currency' => 'GBP', 'extra' => 1]));
        $problem = self::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                ['in' => 'body', 'pointer' => '/owner', 'detail' => 'Minimum string length is 1, found 0'],
                ['in' => 'body', 'pointer' => '/currency', 'detail' => 'The data should match one item from enum'],
            ],
            $problem['errors'],
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $problem['trace_id']);
        self::assertSame([], [...$this->eventStore->streamNames()]);

        ContractAssertions::assertResponseMatchesContract(self::contract(), 'openAccount', $response);
    }

    public function testAnonymousCallersLearnNothingAboutThePayload(): void
    {
        $response = $this->api->handle(self::request('POST', '/accounts', body: ['owner' => '']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertArrayNotHasKey('errors', self::json($response));
    }

    public function testARetriedCreationOpensOneAccount(): void
    {
        $request = self::request('POST', '/accounts', [...self::AUTH, 'Idempotency-Key' => 'open-ada-1'], ['owner' => 'Ada']);

        $first = $this->api->handle($request);
        $second = $this->api->handle($request);

        self::assertSame(self::json($first), self::json($second));
        self::assertSame($first->getHeaderLine('Location'), $second->getHeaderLine('Location'));
        self::assertSame('true', $second->getHeaderLine('Idempotency-Replayed'));
        self::assertCount(1, [...$this->eventStore->streamNames()]);
    }

    public function testErrorsOfTheApplicationGetTheirStatus(): void
    {
        $missing = '/accounts/' . AccountId::generate();

        self::assertSame(404, $this->api->handle(self::request('GET', $missing, [...self::AUTH, 'X-Flags' => '1']))->getStatusCode());
        self::assertSame(404, $this->api->handle(self::request('GET', '/nope'))->getStatusCode());
        self::assertSame('GET, POST', $this->api->handle(self::request('PATCH', '/accounts'))->getHeaderLine('Allow'));
        self::assertSame(501, $this->api->handle(self::request('GET', '/accounts', self::AUTH))->getStatusCode());
        self::assertSame(415, $this->api->handle(self::request('POST', '/accounts', [...self::AUTH, 'Content-Type' => 'text/csv'], 'a;b'))->getStatusCode());
        self::assertSame(400, $this->api->handle(self::request('POST', '/accounts', [...self::AUTH, 'Content-Type' => 'application/json'], '{'))->getStatusCode());
    }

    public function testAResponseTheContractDoesNotDeclareIsAServerError(): void
    {
        $response = $this->api->handle(self::request('DELETE', '/accounts/' . $this->open(), self::AUTH));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['type', 'title', 'status', 'trace_id'], array_keys(self::json($response)));
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertStringContainsString('Status 200 is not declared', (string) $this->logger->records[0]['context']['reason']);
    }

    private function handlers(CommandBus $commands): OperationHandlers
    {
        $accounts = $this->accounts;
        $messages = new MessageFactory();
        $metadata = new MetadataFactory(['actor' => static fn(ServerRequestInterface $request): mixed => $request->getAttribute('subject:bearer')]);
        $view = static fn(Account $account, int $status): ResponseInterface => self::responder()->json(
            $account->toArray(),
            $status,
            ['ETag' => IfMatch::etag($account->version)],
        );

        return new OperationHandlers()
            ->on('openAccount', static function (ServerRequestInterface $request) use ($commands, $accounts, $messages, $metadata, $view): ResponseInterface {
                $id = AccountId::generate();
                $commands->dispatch($messages->create(OpenAccount::class, Input::from($request), ['accountId' => $id]), $metadata->fromRequest($request));

                return $view($accounts->get($id), 201)->withHeader('Location', '/accounts/' . $id);
            })
            ->on('getAccount', static fn(ServerRequestInterface $request): ResponseInterface => $view(
                $accounts->get(AccountId::fromString((string) Input::from($request)->path['accountId'])),
                200,
            ))
            ->on('depositMoney', static function (ServerRequestInterface $request) use ($commands, $accounts, $messages, $metadata): ResponseInterface {
                $input = Input::from($request);
                $id = AccountId::fromString((string) $input->path['accountId']);
                $money = $input->fields()['money'];

                IfMatch::assert($request, $accounts->get($id)->version);
                $commands->dispatch(
                    $messages->create(DepositMoney::class, $input, ['money' => new Money($money['amount'], new Currency($money['currency']))]),
                    $metadata->fromRequest($request),
                );

                return self::responder()->accepted(['accountId' => $id->value, 'version' => $accounts->get($id)->version]);
            })
            // Declared to answer 204 without body: this handler breaks the contract on purpose.
            ->on('closeAccount', static fn(): ResponseInterface => self::responder()->json(['closed' => true]));
    }

    private function open(): string
    {
        return (string) self::json($this->api->handle(self::request('POST', '/accounts', self::AUTH, ['owner' => 'Ada'])))['id'];
    }

    private function balance(string $id): Money
    {
        $balance = $this->accounts->get($id)->toArray()['balance'];

        return new Money($balance['amount'], new Currency($balance['currency']));
    }
}
