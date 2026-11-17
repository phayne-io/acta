<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Http;

use Acta\Oidc\Exception\ProviderError;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Http\Transport;
use Closure;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\StreamFactory;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    /** @var (Closure(): ?RequestInterface)|null reads the request the fake client received */
    private ?Closure $sent = null;

    public function testAGetAsksForJson(): void
    {
        $body = $this->transport(200, '{"issuer":"https://idp.example.test"}')->get('https://idp.example.test/meta', ['Authorization' => 'Bearer t']);

        self::assertSame(['issuer' => 'https://idp.example.test'], $body);
        self::assertSame('GET', $this->sent()->getMethod());
        self::assertSame('application/json', $this->sent()->getHeaderLine('Accept'));
        self::assertSame('Bearer t', $this->sent()->getHeaderLine('Authorization'));
    }

    public function testAPostSendsAForm(): void
    {
        $body = $this->transport(200, '{"access_token":"t"}')->post(
            'https://idp.example.test/token',
            ['grant_type' => 'client_credentials', 'scope' => 'a b', 'redirect_uri' => 'https://app.example.test/cb?x=1&y=2'],
            ['Authorization' => 'Basic abc'],
        );

        self::assertSame(['access_token' => 't'], $body);
        self::assertSame('POST', $this->sent()->getMethod());
        self::assertSame('application/x-www-form-urlencoded', $this->sent()->getHeaderLine('Content-Type'));
        self::assertSame('Basic abc', $this->sent()->getHeaderLine('Authorization'));
        self::assertSame(
            'grant_type=client_credentials&scope=a+b&redirect_uri=https%3A%2F%2Fapp.example.test%2Fcb%3Fx%3D1%26y%3D2',
            (string) $this->sent()->getBody(),
        );
    }

    public function testAnOAuthErrorIsReportedWithItsDetails(): void
    {
        try {
            $this->transport(400, '{"error":"invalid_grant","error_description":"Code expired.","error_uri":"https://idp.example.test/errors"}')
                ->post('https://idp.example.test/token', []);
            self::fail('The error should be reported.');
        } catch (ProviderError $error) {
            self::assertSame('invalid_grant', $error->error);
            self::assertSame('Code expired.', $error->description);
            self::assertSame('https://idp.example.test/errors', $error->uri);
            self::assertSame(400, $error->status);
            self::assertSame('invalid_grant: Code expired.', $error->getMessage());
        }

        try {
            $this->transport(401, '{"error":"invalid_client"}')->get('https://idp.example.test/token');
            self::fail('The error should be reported.');
        } catch (ProviderError $error) {
            self::assertSame('invalid_client', $error->getMessage());
            self::assertNull($error->description);
        }
    }

    public function testAnythingElseThanAJsonObjectIsUnusable(): void
    {
        foreach ([[500, '<html>Bad gateway</html>', 'answered with status 500'], [200, '<html>Login</html>', 'did not answer with a JSON object'], [200, '[1,2]', 'did not answer with a JSON object'], [204, '', 'did not answer with a JSON object'], [302, '{"a":1}', 'answered with status 302']] as [$status, $body, $message]) {
            try {
                $this->transport($status, $body)->get('https://idp.example.test/meta');
                self::fail('The response should be refused.');
            } catch (ProviderUnreachable $exception) {
                self::assertSame('https://idp.example.test/meta ' . $message . '.', $exception->getMessage());
            }
        }
    }

    public function testASuccessfulResponseHoldingAnErrorMemberIsNotAnError(): void
    {
        self::assertSame(['error' => 'none', 'ok' => true], $this->transport(200, '{"error":"none","ok":true}')->get('https://idp.example.test/x'));
    }

    private function sent(): RequestInterface
    {
        return $this->sent?->__invoke() ?? self::fail('No request was sent.');
    }

    private function transport(int $status, string $body): Transport
    {
        $client = new class ($status, $body) implements ClientInterface {
            public ?RequestInterface $sent = null;

            public function __construct(private readonly int $status, private readonly string $body) {}

            #[Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent = $request;

                return new Response(new StreamFactory()->createStream($this->body), $this->status);
            }
        };
        $this->sent = static fn(): ?RequestInterface => $client->sent;

        return new Transport($client, new RequestFactory(), new StreamFactory());
    }
}
