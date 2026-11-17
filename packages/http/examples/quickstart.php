<?php

declare(strict_types=1);

/*
 * A contract-first API in front of an event-sourced domain, in one file.
 *
 *     php examples/quickstart.php
 *
 * The contract is examples/openapi.yaml. The script sends a few requests through the
 * pipeline and prints the responses; no server is started. Amounts are modelled with
 * moneyphp/money and the domain with acta/event-sourcing, both development
 * dependencies of this package.
 */

namespace Acta\Example\Http;

use Acta\EventSourcing\Aggregate\AggregateId;
use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Handles;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\Middleware\TransactionMiddleware;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Message\Command;
use Acta\EventSourcing\Message\DomainEvent;
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
use Acta\Http\Handler\Operation;
use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Handler\OperationHandlers;
use Acta\Http\OpenApi\ContractLoader;
use Acta\Http\OpenApi\SecurityScheme;
use Acta\Http\Pipeline;
use Acta\Http\Problem\ExceptionMapper;
use Acta\Http\Problem\ProblemDetailsMiddleware;
use Acta\Http\Response\Responder;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Security\Authenticator;
use Acta\Http\Security\SecurityMiddleware;
use Acta\Http\Trace\CorrelationMiddleware;
use Acta\Http\Validation\Input;
use Acta\Http\Validation\RequestValidationMiddleware;
use Acta\Http\Validation\RequestValidator;
use Acta\Http\Validation\ResponseValidationMiddleware;
use Acta\Http\Validation\ResponseValidator;
use DomainException;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Money\Currency;
use Money\Money;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

require __DIR__ . '/../vendor/autoload.php';

// --- Domain: knows neither HTTP nor the contract ---------------------------------------

final readonly class AccountId extends AggregateId {}

final readonly class AccountOpened implements DomainEvent
{
    public function __construct(public AccountId $accountId, public string $owner, public Currency $currency) {}
}

final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(public AccountId $accountId, public Money $money) {}
}

final class Account extends AggregateRoot
{
    private AccountId $id;

    private(set) string $owner;

    private(set) Money $balance;

    private function __construct() {}

    public static function open(AccountId $id, string $owner, Currency $currency): self
    {
        return new self()->recordThat(new AccountOpened($id, $owner, $currency));
    }

    public function deposit(Money $money): void
    {
        $money->isSameCurrency($this->balance) || throw new DomainException(sprintf('This account holds %s.', $this->balance->getCurrency()));
        $money->isPositive() || throw new DomainException('A deposit must be positive.');

        $this->recordThat(new MoneyDeposited($this->id, $money));
    }

    public function aggregateId(): string
    {
        return $this->id->value;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof AccountOpened => [$this->id, $this->owner, $this->balance] = [$event->accountId, $event->owner, new Money(0, $event->currency)],
            $event instanceof MoneyDeposited => $this->balance = $this->balance->add($event->money),
            default => null,
        };
    }
}

// --- Application -----------------------------------------------------------------------

final readonly class OpenAccount implements Command
{
    public function __construct(public AccountId $accountId, public string $owner, public string $currency = 'EUR') {}
}

final readonly class DepositMoney implements Command
{
    public function __construct(public AccountId $accountId, public Money $money) {}
}

final readonly class AccountCommandHandlers
{
    /**
     * @param AggregateRepository<Account> $accounts
     */
    public function __construct(private AggregateRepository $accounts) {}

    #[Handles]
    public function open(OpenAccount $command): void
    {
        $this->accounts->save(Account::open($command->accountId, $command->owner, new Currency($command->currency)));
    }

    #[Handles]
    public function deposit(DepositMoney $command): void
    {
        $account = $this->accounts->get($command->accountId);
        $account->deposit($command->money);

        $this->accounts->save($account);
    }
}

// --- Primary adapter: one request handler per operation of the contract ----------------

/**
 * What the three handlers share: how an account is represented.
 */
final readonly class AccountView
{
    /**
     * @param AggregateRepository<Account> $accounts
     */
    public function __construct(private AggregateRepository $accounts, private Responder $responder) {}

    public function respond(AccountId $id, int $status = 200): ResponseInterface
    {
        $account = $this->accounts->get($id);

        return $this->responder->json(
            [
                'id' => $id->value,
                'owner' => $account->owner,
                'balance' => ['amount' => $account->balance->getAmount(), 'currency' => $account->balance->getCurrency()->getCode()],
                'version' => $account->version,
            ],
            $status,
            ['ETag' => IfMatch::etag($account->version)],
        );
    }

    public function version(AccountId $id): int
    {
        return $this->accounts->get($id)->version;
    }
}

#[Operation('openAccount')]
final readonly class OpenAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private CommandBus $commands,
        private MessageFactory $messages,
        private MetadataFactory $metadata,
        private AccountView $view,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = AccountId::generate();
        $command = $this->messages->create(OpenAccount::class, Input::from($request), ['accountId' => $id]);

        $this->commands->dispatch($command, $this->metadata->fromRequest($request));

        return $this->view->respond($id, 201)->withHeader('Location', '/accounts/' . $id);
    }
}

#[Operation('getAccount')]
final readonly class GetAccountHandler implements RequestHandlerInterface
{
    public function __construct(private AccountView $view) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->respond(AccountId::fromString(Input::from($request)->path['accountId']));
    }
}

#[Operation('depositMoney')]
final readonly class DepositMoneyHandler implements RequestHandlerInterface
{
    public function __construct(
        private CommandBus $commands,
        private MessageFactory $messages,
        private MetadataFactory $metadata,
        private AccountView $view,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = Input::from($request);
        $id = AccountId::fromString($input->path['accountId']);

        // Refuses the deposit when the client has not seen the latest version of the account.
        IfMatch::assert($request, $this->view->version($id));

        // The wire format of an amount is translated here, in the adapter.
        $money = new Money($input->body['amount'], new Currency($input->body['currency']));
        $command = $this->messages->create(DepositMoney::class, $input, ['money' => $money]);

        $this->commands->dispatch($command, $this->metadata->fromRequest($request));

        return $this->view->respond($id);
    }
}

/**
 * Stands in for a real Bearer JWT verifier: tokens are looked up in a list.
 */
final readonly class DemoAuthenticator implements Authenticator
{
    /**
     * @param array<string, array{string, list<string>}> $tokens token => subject and granted scopes
     */
    public function __construct(private array $tokens) {}

    public function authenticate(ServerRequestInterface $request, SecurityScheme $scheme, array $scopes): ServerRequestInterface
    {
        $token = substr($request->getHeaderLine('Authorization'), strlen('Bearer '));
        [$subject, $granted] = $this->tokens[$token] ?? throw HttpException::unauthorized(challenge: 'Bearer error="invalid_token"');

        if (array_diff($scopes, $granted) !== []) {
            throw HttpException::forbidden('This token lacks a required scope.', 'Bearer error="insufficient_scope"');
        }

        return $request->withAttribute('subject', $subject);
    }
}

/**
 * Business rules reach the client as 422, without the domain knowing HTTP.
 */
final readonly class BusinessRuleMapper implements ExceptionMapper
{
    public function map(Throwable $exception): ?HttpException
    {
        return $exception instanceof DomainException
            ? new HttpException(422, $exception->getMessage(), title: 'Business rule violated', previous: $exception)
            : null;
    }
}

// --- Wiring ----------------------------------------------------------------------------

$types = new MessageTypeMap(AccountOpened::class, MoneyDeposited::class);
$causation = new CausationMiddleware('actor');
$envelopes = new EnvelopeFactory($types, new SystemClock(), $causation);
$eventStore = new InMemoryEventStore(new MessageSerializer($types));
$accounts = new AggregateRepository(Account::class, 'account', $eventStore, $envelopes);
$commands = new DefaultCommandBus(
    new HandlerMap()->register(new AccountCommandHandlers($accounts)),
    $envelopes,
    $causation,
    new TransactionMiddleware($eventStore),
);

$contract = ContractLoader::load(__DIR__ . '/openapi.yaml');
$responder = new Responder(new ResponseFactory(), new StreamFactory());
$messages = new MessageFactory();
$metadata = new MetadataFactory(['actor' => static fn(ServerRequestInterface $request): mixed => $request->getAttribute('subject')]);
$view = new AccountView($accounts, $responder);

$handlers = new OperationHandlers()->register(
    new OpenAccountHandler($commands, $messages, $metadata, $view),
    new GetAccountHandler($view),
    new DepositMoneyHandler($commands, $messages, $metadata, $view),
);

$api = new Pipeline(
    new OperationDispatcher($handlers),
    new CorrelationMiddleware(),                                         // a trace for every request
    new ProblemDetailsMiddleware($responder, [new EventSourcingExceptionMapper(), new BusinessRuleMapper()]),
    new RoutingMiddleware(new Router($contract)),                        // the paths of the contract
    new SecurityMiddleware($contract, ['bearer' => new DemoAuthenticator([
        'token-ada' => ['ada', ['accounts:write']],
        'token-guest' => ['guest', []],
    ])]),                                                                // its security requirements
    new RequestValidationMiddleware(new RequestValidator($contract)),    // its parameters and bodies
    new ResponseValidationMiddleware(new ResponseValidator($contract)),  // and its responses
);

// --- Use -------------------------------------------------------------------------------

$send = static function (string $method, string $path, array $headers = [], ?array $body = null) use ($api): ResponseInterface {
    $stream = new StreamFactory()->createStream($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    $headers = $body === null ? $headers : ['Content-Type' => 'application/json', ...$headers];
    $response = $api->handle(new ServerRequest([], [], $path, $method, $stream, $headers));

    printf("%s %s\n  %d %s\n\n", $method, preg_replace('/[0-9a-f-]{36}/', '{id}', $path), $response->getStatusCode(), $response->getBody());

    return $response;
};
$ada = ['Authorization' => 'Bearer token-ada'];

$opened = $send('POST', '/accounts', $ada, ['owner' => 'Ada', 'currency' => 'EUR']);
$account = $opened->getHeaderLine('Location');

$send('POST', $account . '/deposits', [...$ada, 'If-Match' => $opened->getHeaderLine('ETag')], ['amount' => '12000', 'currency' => 'EUR']);
$send('GET', $account, $ada);

// What the pipeline refuses before the application is involved:
$send('POST', '/accounts', $ada, ['owner' => '', 'currency' => 'GBP']);                                   // the contract
$send('POST', '/accounts', [], ['owner' => 'Eve']);                                                        // no token
$send('POST', '/accounts', ['Authorization' => 'Bearer token-guest'], ['owner' => 'Eve']);                 // no scope
$send('PUT', '/accounts');                                                                                 // no such operation

// What the application refuses:
$send('POST', $account . '/deposits', [...$ada, 'If-Match' => '"1"'], ['amount' => '500', 'currency' => 'EUR']); // stale version
$send('POST', $account . '/deposits', $ada, ['amount' => '500', 'currency' => 'USD']);                           // business rule
$send('GET', '/accounts/' . AccountId::generate(), $ada);                                                        // unknown account

foreach ($eventStore->load($accounts->streamName(basename($account))) as $recorded) {
    printf(
        "v%d  %-14s  actor %s  correlation %s\n",
        $recorded->streamVersion,
        basename(str_replace('\\', '/', $recorded->name)),
        $recorded->metadata->get('actor'),
        $recorded->metadata->get(Metadata::CORRELATION_ID),
    );
}
