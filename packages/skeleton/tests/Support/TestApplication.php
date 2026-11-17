<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\Oidc\Cache\ArrayCache;
use Acta\Oidc\Testing\FakeIdentityProvider;
use Acta\Webhook\Signature\StandardWebhooks;
use App\Platform\Container\Resolve;
use App\Platform\Settings;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\ServiceManager\ServiceManager;
use Mezzio\Application;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The whole application, wired as in production, with the outside world replaced: the
 * identity provider and the webhook subscribers are in the process, and nothing is sent
 * over the network.
 *
 * ```php
 * $app = TestApplication::boot();
 * $response = $app->request('POST', '/accounts', ['owner' => 'Ada'], $app->token('banking:write'));
 * ```
 */
final class TestApplication
{
    public const string AUDIENCE = 'https://api.example.test';

    public const string SUBSCRIBER_URL = 'https://subscriber.example.test/hooks';

    public readonly FakeIdentityProvider $identityProvider;

    public readonly FakeSubscriber $subscriber;

    public readonly RecordingLogger $logger;

    public readonly string $paymentsSecret;

    public readonly string $subscriberSecret;

    private ?Application $http = null;

    private function __construct(
        public readonly ServiceManager $container,
    ) {
        $this->identityProvider = new FakeIdentityProvider(new ResponseFactory(), new StreamFactory());
        $this->subscriber = new FakeSubscriber();
        $this->logger = new RecordingLogger();
        $this->paymentsSecret = $this->setting('acta.webhooks.secrets.payments');
        $this->subscriberSecret = $this->setting('acta.webhooks.subscriptions.0.secret');

        $container->setAllowOverride(true);
        $container->setService(ClientInterface::class, new OutsideWorld($this->identityProvider, $this->subscriber));
        $container->setService(CacheInterface::class, new ArrayCache());
        $container->setService(LoggerInterface::class, $this->logger);
    }

    /**
     * Boots the application.
     *
     * @param 'memory'|'postgres'     $persistence where state is kept
     * @param array<array-key, mixed> $config      settings that replace those of the application
     */
    public static function boot(string $persistence = 'memory', array $config = []): self
    {
        $base = require dirname(__DIR__, 2) . '/config/config.php';

        $base = is_array($base) ? $base : [];

        // Every source of webhooks the contexts declare gets a secret of its own.
        $sources = array_keys((array) ($base['acta']['webhooks']['secrets'] ?? []));
        $secrets = array_combine($sources, array_map(static fn(): string => StandardWebhooks::generateSecret(), $sources));

        $config = array_replace_recursive($base, [
            'debug' => true,
            'acta' => [
                'persistence' => $persistence,
                'database' => [
                    'dsn' => (string) getenv('TEST_DATABASE_DSN'),
                    'user' => (string) getenv('TEST_DATABASE_USER'),
                    'password' => (string) getenv('TEST_DATABASE_PASSWORD'),
                ],
                // No worker runs during a test: read models follow each command.
                'projections_after_command' => ['banking_accounts', 'webhooks'],
                'http' => ['contract_cache' => '', 'response_validation' => 'fail'],
                'oidc' => ['issuer' => 'https://idp.example.test', 'audience' => self::AUDIENCE, 'client_id' => 'console'],
                'webhooks' => [
                    'secrets' => $secrets,
                    'subscriptions' => [
                        ['id' => 'subscriber', 'url' => self::SUBSCRIBER_URL, 'secret' => StandardWebhooks::generateSecret()],
                    ],
                ],
            ],
        ], $config);

        $dependencies = is_array($config['dependencies'] ?? null) ? $config['dependencies'] : [];
        $dependencies['services'] = ['config' => $config];

        return new self(new ServiceManager($dependencies));
    }

    /**
     * Returns a service of the application.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function get(string $type): object
    {
        return Resolve::from($this->container, $type);
    }

    /**
     * Returns an access token for this API, as the identity provider would issue it.
     *
     * @return array<non-empty-string, string> the `Authorization` header
     */
    public function token(string $scope = 'banking:read banking:write', string $subject = 'ada'): array
    {
        $token = $this->identityProvider->issueAccessToken(['sub' => $subject, 'aud' => self::AUDIENCE, 'scope' => $scope]);

        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Sends a request through the HTTP pipeline, exactly as public/index.php would.
     *
     * @param array<array-key, mixed>|string|null $body    encoded as JSON unless it is a string
     * @param array<non-empty-string, string>     $headers
     */
    public function request(string $method, string $path, array|string|null $body = null, array $headers = []): ResponseInterface
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
            $headers = ['Content-Type' => 'application/json', ...$headers];
        }

        [$path, $query] = [...explode('?', $path, 2), ''];
        parse_str($query, $queryParams);

        return $this->http()->handle(new ServerRequest(
            uri: 'https://api.example.test' . $path . ($query === '' ? '' : '?' . $query),
            method: $method,
            body: new StreamFactory()->createStream($body ?? ''),
            headers: $headers,
            queryParams: $queryParams,
        ));
    }

    /**
     * Sends a webhook as the payment provider would, signed with its secret.
     *
     * @param array<string, mixed> $payload
     */
    public function paymentWebhook(string $deliveryId, array $payload, ?string $secret = null): ResponseInterface
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json'];
        $signature = new StandardWebhooks($secret ?? $this->paymentsSecret)->sign($deliveryId, $this->get(ClockInterface::class)->now(), $body);

        foreach ($signature as $name => $value) {
            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return $this->request('POST', '/webhooks/payments', $body, $headers);
    }

    /**
     * Runs a console command, exactly as bin/console would.
     *
     * @param array<string, mixed> $input arguments and options, the latter with their dashes
     */
    public function console(string $command, array $input = []): ApplicationTester
    {
        $application = $this->get(ConsoleApplication::class);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => $command, ...$input], ['interactive' => false]);

        return $tester;
    }

    /**
     * Creates the tables and empties them. PostgreSQL only.
     */
    public function resetDatabase(): void
    {
        $this->console('es:migrate');

        $this->get(PDO::class)->exec(
            'TRUNCATE event_store, projections, snapshots, http_idempotency, webhook_received, webhook_deliveries, banking_accounts RESTART IDENTITY',
        );
    }

    public function eventStore(): EventStore
    {
        return $this->get(EventStore::class);
    }

    private function http(): Application
    {
        if ($this->http === null) {
            $root = dirname(__DIR__, 2);
            $this->http = $this->get(Application::class);

            (require $root . '/config/pipeline.php')($this->http);
            (require $root . '/config/routes.php')($this->http);
        }

        return $this->http;
    }

    private function setting(string $path): string
    {
        return $this->get(Settings::class)->string($path);
    }
}
