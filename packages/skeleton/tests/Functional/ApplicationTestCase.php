<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\Message\Metadata;
use Acta\Http\OpenApi\Contract;
use Acta\Http\Testing\ContractAssertions;
use Acta\Webhook\Signature\StandardWebhooks;
use App\Tests\Support\TestApplication;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The application from end to end: requests go through the real pipeline, commands
 * through the real buses, events into a real event store. Only the identity provider
 * and the webhook subscribers are replaced.
 *
 * The scenarios are the same whatever keeps the state; subclasses say what does.
 */
abstract class ApplicationTestCase extends TestCase
{
    protected TestApplication $app;

    /**
     * Boots the application the scenarios run against.
     */
    abstract protected function boot(): TestApplication;

    protected function setUp(): void
    {
        $this->app = $this->boot();
    }

    protected function tearDown(): void
    {
        // A skipped test never booted the application.
        if (!isset($this->app)) {
            return;
        }

        // Whatever the scenario, the application is not expected to have reported an error of its own.
        self::assertSame([], array_filter(
            $this->app->logger->errors(),
            static fn(string $message): bool => !str_contains($message, 'Handling {kind}'),
        ));
    }

    public function testAnAccountLivesItsLife(): void
    {
        $account = $this->openAccount('Ada');

        $opened = $this->get($account);
        self::assertSame(['id' => basename($account), 'owner' => 'Ada', 'balance' => ['amount' => '0', 'currency' => 'EUR'], 'version' => 1], self::json($opened));
        self::assertSame('"1"', $opened->getHeaderLine('ETag'));

        self::assertSame(204, $this->post($account . '/deposits', ['amount' => '12000', 'currency' => 'EUR', 'reference' => 'salary'])->getStatusCode());
        self::assertSame(204, $this->post($account . '/withdrawals', ['amount' => '2000', 'currency' => 'EUR'])->getStatusCode());

        $after = $this->get($account);
        self::assertSame(['amount' => '10000', 'currency' => 'EUR'], self::json($after)['balance']);
        self::assertSame('"3"', $after->getHeaderLine('ETag'));
        ContractAssertions::assertResponseMatchesContract($this->app->get(Contract::class), 'getAccount', $after);
    }

    public function testAccountsAreListed(): void
    {
        $this->openAccount('Ada');
        $this->openAccount('Grace', 'USD');
        $this->openAccount('Ada');

        self::assertCount(3, self::json($this->get('/accounts'))['items']);
        self::assertCount(2, self::json($this->get('/accounts?owner=Ada'))['items']);
        self::assertCount(1, self::json($this->get('/accounts?owner=Ada&limit=1'))['items']);
        self::assertSame('USD', self::json($this->get('/accounts?owner=Grace'))['items'][0]['balance']['currency']);
    }

    public function testTheRulesOfTheBankAreReportedAsProblems(): void
    {
        $account = $this->openAccount('Ada');

        $refused = $this->post($account . '/withdrawals', ['amount' => '1', 'currency' => 'EUR']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('application/problem+json', $refused->getHeaderLine('Content-Type'));
        self::assertSame('urn:problem:banking:insufficient-funds', self::json($refused)['type']);
        self::assertSame('Business rule violated', self::json($refused)['title']);

        self::assertSame('urn:problem:banking:currency-mismatch', self::json($this->post($account . '/deposits', ['amount' => '1', 'currency' => 'USD']))['type']);
        self::assertSame(1, self::json($this->get($account))['version']);
    }

    public function testWhatTheContractDoesNotAllowNeverReachesTheApplication(): void
    {
        $token = $this->app->token();

        $invalid = $this->app->request('POST', '/accounts', ['owner' => '', 'currency' => 'XXX', 'extra' => true], $token);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertGreaterThanOrEqual(2, count(self::json($invalid)['errors']));

        self::assertSame(422, $this->app->request('GET', '/accounts/not-a-uuid', null, $token)->getStatusCode());
        self::assertSame(422, $this->app->request('GET', '/accounts?limit=1000', null, $token)->getStatusCode());
        self::assertSame(415, $this->app->request('POST', '/accounts', 'owner=Ada', ['Content-Type' => 'text/plain', ...$token])->getStatusCode());
        self::assertSame(404, $this->app->request('GET', '/nowhere', null, $token)->getStatusCode());
        self::assertSame(405, $this->app->request('DELETE', '/accounts', null, $token)->getStatusCode());
        self::assertSame(404, $this->get('/accounts/' . '01a1eb64-87b8-7527-99d9-acfbd5ff1517')->getStatusCode());
        self::assertSame([], [...$this->app->eventStore()->read(Selector::all())]);
    }

    public function testOperationsRequireABearerTokenWithTheirScopes(): void
    {
        $missing = $this->app->request('GET', '/accounts');
        self::assertSame(401, $missing->getStatusCode());
        self::assertSame('Bearer', $missing->getHeaderLine('WWW-Authenticate'));

        $forged = $this->app->request('GET', '/accounts', null, ['Authorization' => 'Bearer ' . str_repeat('x', 40)]);
        self::assertSame(401, $forged->getStatusCode());
        self::assertStringContainsString('invalid_token', $forged->getHeaderLine('WWW-Authenticate'));

        $otherAudience = $this->app->identityProvider->issueAccessToken(['aud' => 'https://other.example.test', 'scope' => 'banking:read']);
        self::assertSame(401, $this->app->request('GET', '/accounts', null, ['Authorization' => 'Bearer ' . $otherAudience])->getStatusCode());

        $readOnly = $this->app->request('POST', '/accounts', ['owner' => 'Eve'], $this->app->token('banking:read'));
        self::assertSame(403, $readOnly->getStatusCode());
        self::assertStringContainsString('insufficient_scope', $readOnly->getHeaderLine('WWW-Authenticate'));

        self::assertSame(200, $this->app->request('GET', '/accounts', null, $this->app->token('banking:read'))->getStatusCode());
    }

    public function testTheKeysOfTheIdentityProviderAreFetchedOnce(): void
    {
        $this->get('/accounts');
        $this->get('/accounts');
        $this->get('/accounts');

        self::assertSame(1, $this->app->identityProvider->requestCount('/.well-known/openid-configuration'));
        self::assertSame(1, $this->app->identityProvider->requestCount('/jwks'));
    }

    public function testAClientActsOnTheVersionItHasRead(): void
    {
        $account = $this->openAccount('Ada');
        $etag = $this->get($account)->getHeaderLine('ETag');

        self::assertSame(204, $this->post($account . '/deposits', ['amount' => '100', 'currency' => 'EUR'], ['If-Match' => $etag])->getStatusCode());

        // The same client, still holding what it read before its own deposit.
        $stale = $this->post($account . '/deposits', ['amount' => '100', 'currency' => 'EUR'], ['If-Match' => $etag]);

        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(['amount' => '100', 'currency' => 'EUR'], self::json($this->get($account))['balance']);
        self::assertSame(400, $this->post($account . '/deposits', ['amount' => '100', 'currency' => 'EUR'], ['If-Match' => 'yesterday'])->getStatusCode());
    }

    public function testARetriedRequestIsHandledOnce(): void
    {
        $account = $this->openAccount('Ada');
        $deposit = ['amount' => '100', 'currency' => 'EUR'];

        $first = $this->post($account . '/deposits', $deposit, ['Idempotency-Key' => 'k-1']);
        $retry = $this->post($account . '/deposits', $deposit, ['Idempotency-Key' => 'k-1']);

        self::assertSame([204, 204], [$first->getStatusCode(), $retry->getStatusCode()]);
        self::assertSame(['amount' => '100', 'currency' => 'EUR'], self::json($this->get($account))['balance']);

        // The same key for another request is a mistake of the client.
        self::assertSame(422, $this->post($account . '/deposits', ['amount' => '999', 'currency' => 'EUR'], ['Idempotency-Key' => 'k-1'])->getStatusCode());

        $created = $this->app->request('POST', '/accounts', ['owner' => 'Grace'], [...$this->app->token(), 'Idempotency-Key' => 'k-2']);
        $again = $this->app->request('POST', '/accounts', ['owner' => 'Grace'], [...$this->app->token(), 'Idempotency-Key' => 'k-2']);

        self::assertSame($created->getHeaderLine('Location'), $again->getHeaderLine('Location'));
        self::assertCount(1, self::json($this->get('/accounts?owner=Grace'))['items']);
    }

    public function testEveryEventSaysWhoCausedItAndInWhichRequest(): void
    {
        $response = $this->app->request('POST', '/accounts', ['owner' => 'Ada'], [
            ...$this->app->token(subject: 'ada@example.test'),
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertStringStartsWith('00-0af7651916cd43dd8448eb211c80319c-', $response->getHeaderLine('traceparent'));

        $events = [...$this->app->eventStore()->read(Selector::all())];
        self::assertCount(1, $events);
        self::assertInstanceOf(RecordedEvent::class, $events[0]);
        self::assertSame('banking.account-opened', $events[0]->name);
        self::assertSame('ada@example.test', $events[0]->metadata->get('actor'));
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $events[0]->metadata->get(Metadata::CORRELATION_ID));
        self::assertNotNull($events[0]->metadata->get(Metadata::CAUSATION_ID));
    }

    public function testThePaymentProviderNotifiesDeposits(): void
    {
        $account = $this->openAccount('Ada');
        $payment = ['type' => 'payment.succeeded', 'data' => ['id' => 'pay_1', 'account' => basename($account), 'amount' => '500', 'currency' => 'EUR']];

        self::assertSame(204, $this->app->paymentWebhook('msg_1', $payment)->getStatusCode());
        // Providers deliver at least once: the second delivery is acknowledged and ignored.
        self::assertSame(204, $this->app->paymentWebhook('msg_1', $payment)->getStatusCode());
        self::assertSame(204, $this->app->paymentWebhook('msg_2', ['type' => 'ping'])->getStatusCode());

        self::assertSame(['amount' => '500', 'currency' => 'EUR'], self::json($this->get($account))['balance']);

        self::assertSame(422, $this->app->paymentWebhook('msg_3', ['type' => 'refund.created'])->getStatusCode());
        self::assertSame(401, $this->app->paymentWebhook('msg_4', $payment, StandardWebhooks::generateSecret())->getStatusCode());
        self::assertSame(404, $this->app->request('POST', '/webhooks/nobody', '{}')->getStatusCode());
        self::assertSame(2, self::json($this->get($account))['version']);
    }

    public function testSubscribersAreNotifiedOfWhatHappens(): void
    {
        $account = $this->openAccount('Ada');
        $this->post($account . '/deposits', ['amount' => '12000', 'currency' => 'EUR']);
        $this->post($account . '/withdrawals', ['amount' => '2000', 'currency' => 'EUR']);

        $delivery = $this->app->console('webhook:deliver');

        self::assertSame(0, $delivery->getStatusCode(), $delivery->getDisplay());
        self::assertStringContainsString('3 delivered', $delivery->getDisplay());

        $notifications = $this->app->subscriber->notifications();
        self::assertSame(['account.opened', 'account.credited', 'account.debited'], array_column($notifications, 'type'));
        self::assertSame(
            ['accountId' => basename($account), 'amount' => ['amount' => '2000', 'currency' => 'EUR']],
            $notifications[2]['data'],
        );

        // What a subscriber does on its side: verify the signature with the shared secret.
        $request = $this->app->subscriber->requests[0];
        self::assertSame(TestApplication::SUBSCRIBER_URL, (string) $request->getUri());
        new StandardWebhooks($this->app->subscriberSecret)->verify($request, (string) $request->getBody());

        // Nothing is sent twice.
        self::assertStringContainsString('0 delivered', $this->app->console('webhook:deliver')->getDisplay());
    }

    public function testADeliveryThatFailsIsRetriedLater(): void
    {
        $this->app->subscriber->status = 503;
        $this->openAccount('Ada');

        self::assertStringContainsString('1 to retry', $this->app->console('webhook:deliver')->getDisplay());
        self::assertStringContainsString('No dead letter', $this->app->console('webhook:dead-letters')->getDisplay());
    }

    public function testTheConsoleDrivesTheSameUseCases(): void
    {
        $opened = $this->app->console('banking:account:open', ['owner' => 'Grace', '--currency' => 'USD']);
        self::assertSame(0, $opened->getStatusCode(), $opened->getDisplay());
        preg_match('/Account (\S+) opened/', $opened->getDisplay(), $matches);

        $shown = $this->app->console('banking:account:show', ['account' => $matches[1] ?? self::fail('The command did not say which account it opened.')]);
        self::assertSame(0, $shown->getStatusCode());
        self::assertStringContainsString('Grace', $shown->getDisplay());
        self::assertStringContainsString('0 USD', $shown->getDisplay());

        self::assertSame(1, $this->app->console('banking:account:show', ['account' => '01a1eb64-87b8-7527-99d9-acfbd5ff1517'])->getStatusCode());
        self::assertSame(1, $this->app->console('banking:account:open', ['owner' => ' '])->getStatusCode());

        $status = $this->app->console('projection:status')->getDisplay();
        self::assertStringContainsString('banking_accounts', $status);
        self::assertStringContainsString('webhooks', $status);
    }

    public function testAReadModelIsRebuiltFromTheEvents(): void
    {
        $account = $this->openAccount('Ada');
        $this->post($account . '/deposits', ['amount' => '700', 'currency' => 'EUR']);

        $rebuilt = $this->app->console('projection:rebuild', ['name' => 'banking_accounts', '--force' => true]);

        self::assertSame(0, $rebuilt->getStatusCode(), $rebuilt->getDisplay());
        self::assertSame(['amount' => '700', 'currency' => 'EUR'], self::json($this->get($account))['balance']);
    }

    public function testTheRoutesOutsideTheContractAnswer(): void
    {
        $health = $this->app->request('GET', '/health');
        self::assertSame(['status' => 'ok'], self::json($health));
        self::assertNotSame('', $health->getHeaderLine('traceparent'));

        $contract = self::json($this->app->request('GET', '/openapi.json'));
        self::assertSame('3.1.0', $contract['openapi']);
        self::assertArrayHasKey('/accounts', $contract['paths']);
    }

    /**
     * Opens an account and returns its location.
     */
    protected function openAccount(string $owner, string $currency = 'EUR'): string
    {
        $response = $this->post('/accounts', ['owner' => $owner, 'currency' => $currency]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('/accounts/' . self::json($response)['id'], $response->getHeaderLine('Location'));

        return $response->getHeaderLine('Location');
    }

    protected function get(string $path): ResponseInterface
    {
        return $this->app->request('GET', $path, null, $this->app->token());
    }

    /**
     * @param array<string, mixed>            $body
     * @param array<non-empty-string, string> $headers
     */
    protected function post(string $path, array $body, array $headers = []): ResponseInterface
    {
        return $this->app->request('POST', $path, $body, [...$this->app->token(), ...$headers]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
