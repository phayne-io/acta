<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Unit;

use Acta\Webhook\Exception\ConfigurationError;
use Acta\Webhook\Exception\InvalidSignature;
use Acta\Webhook\Signature\HmacEncoding;
use Acta\Webhook\Signature\HmacSignature;
use Acta\Webhook\Signature\SignatureScheme;
use Acta\Webhook\Signature\StandardWebhooks;
use Acta\Webhook\Tests\Fixture\FrozenClock;
use DateTimeImmutable;
use Laminas\Diactoros\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SignatureTest extends TestCase
{
    private const string BODY = '{"type":"payment.succeeded","data":{"id":"pay_1"}}';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-01-01T12:00:00+00:00'));
    }

    public function testItProducesTheSignatureOfTheStandardWebhooksSpecification(): void
    {
        // The example published with the specification.
        $scheme = new StandardWebhooks('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');

        self::assertSame(
            [
                'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
                'webhook-timestamp' => '1614265330',
                'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
            ],
            $scheme->sign('msg_p5jXN8AQM9LWM0D4loKWxJek', new DateTimeImmutable('@1614265330'), '{"test": 2432232314}'),
        );
    }

    public function testItVerifiesTheSignatureOfGitHub(): void
    {
        // The example of the GitHub documentation.
        $scheme = new HmacSignature('X-Hub-Signature-256', "It's a Secret to Everybody", idHeader: 'X-GitHub-Delivery');
        $request = self::request([
            'X-Hub-Signature-256' => 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17',
            'X-GitHub-Delivery' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        ]);

        $verified = $scheme->verify($request, 'Hello, World!');

        self::assertSame('72d3162e-cc78-11e3-81ab-4c9367dc0958', $verified->deliveryId);
        self::assertNull($verified->signedAt);
    }

    public function testAStandardWebhookIsVerifiedWithItsIdentifierAndTimestamp(): void
    {
        $scheme = new StandardWebhooks('secret', clock: $this->clock);
        $verified = $scheme->verify(self::request($scheme->sign('msg_1', $this->clock->now(), self::BODY)), self::BODY);

        self::assertSame('msg_1', $verified->deliveryId);
        self::assertEquals($this->clock->now(), $verified->signedAt);
    }

    /**
     * @param array<string, string|null> $changes headers to replace, or to remove when null
     */
    #[DataProvider('tamperedStandardWebhooks')]
    public function testATamperedStandardWebhookIsRefused(array $changes, string $body, string $message): void
    {
        $scheme = new StandardWebhooks('secret', clock: $this->clock);
        $headers = array_filter([...$scheme->sign('msg_1', $this->clock->now(), self::BODY), ...$changes], static fn(?string $value): bool => $value !== null);

        $this->expectException(InvalidSignature::class);
        $this->expectExceptionMessage($message);

        $scheme->verify(self::request($headers), $body);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string, string}>
     */
    public static function tamperedStandardWebhooks(): iterable
    {
        $required = 'The webhook-id, webhook-timestamp and webhook-signature headers are required.';
        $invalid = 'The signature of the webhook is not valid.';

        yield 'another body' => [[], '{"type":"payment.succeeded","data":{"id":"pay_2"}}', $invalid];
        yield 'another identifier' => [['webhook-id' => 'msg_2'], self::BODY, $invalid];
        yield 'another timestamp' => [['webhook-timestamp' => '1767268801'], self::BODY, $invalid];
        yield 'another signature' => [['webhook-signature' => 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='], self::BODY, $invalid];
        yield 'unknown version only' => [['webhook-signature' => 'v2,AAAA'], self::BODY, $invalid];
        yield 'no signature' => [['webhook-signature' => null], self::BODY, $required];
        yield 'no identifier' => [['webhook-id' => null], self::BODY, $required];
        yield 'no timestamp' => [['webhook-timestamp' => null], self::BODY, $required];
        yield 'timestamp that is not a number' => [['webhook-timestamp' => 'yesterday'], self::BODY, $required];
    }

    public function testAnOldOrFutureStandardWebhookIsRefused(): void
    {
        $scheme = new StandardWebhooks('secret', tolerance: 300, clock: $this->clock);
        $at = fn(string $modifier): RequestInterface => self::request($scheme->sign('msg_1', $this->clock->now()->modify($modifier), self::BODY));

        $scheme->verify($at('-300 seconds'), self::BODY);
        $scheme->verify($at('+300 seconds'), self::BODY);

        foreach (['-301 seconds', '+301 seconds'] as $modifier) {
            try {
                $scheme->verify($at($modifier), self::BODY);
                self::fail('The webhook should be refused.');
            } catch (InvalidSignature $exception) {
                self::assertSame('The webhook was signed too long ago, or in the future.', $exception->getMessage());
            }
        }
    }

    public function testSecretsAreRotatedWithoutDowntime(): void
    {
        $old = new StandardWebhooks('old-secret', clock: $this->clock);
        $both = new StandardWebhooks(['new-secret', 'old-secret'], clock: $this->clock);
        $new = new StandardWebhooks('new-secret', clock: $this->clock);
        $now = $this->clock->now();

        // During the rotation the sender signs with both secrets, and the receiver accepts both.
        $both->verify(self::request($old->sign('msg_1', $now, self::BODY)), self::BODY);
        $both->verify(self::request($new->sign('msg_1', $now, self::BODY)), self::BODY);
        $old->verify(self::request($both->sign('msg_1', $now, self::BODY)), self::BODY);
        $new->verify(self::request($both->sign('msg_1', $now, self::BODY)), self::BODY);

        self::assertCount(2, explode(' ', $both->sign('msg_1', $now, self::BODY)['webhook-signature']));

        $this->expectException(InvalidSignature::class);

        $new->verify(self::request($old->sign('msg_1', $now, self::BODY)), self::BODY);
    }

    public function testGeneratedSecretsFollowTheSpecification(): void
    {
        $secret = StandardWebhooks::generateSecret();

        self::assertMatchesRegularExpression('~^whsec_[A-Za-z0-9+/]{43}=$~', $secret);
        self::assertNotSame($secret, StandardWebhooks::generateSecret());

        new StandardWebhooks($secret);
    }

    /**
     * @param string|list<string> $secrets
     */
    #[DataProvider('unusableSecrets')]
    public function testAnUnusableSecretIsAConfigurationError(string|array $secrets): void
    {
        $this->expectException(ConfigurationError::class);

        new StandardWebhooks($secrets);
    }

    /**
     * @return iterable<string, array{string|list<string>}>
     */
    public static function unusableSecrets(): iterable
    {
        yield 'none' => [[]];
        yield 'empty' => [''];
        yield 'prefix only' => ['whsec_'];
        yield 'not base64' => ['whsec_***'];
    }

    public function testAnHmacWebhookRoundTrips(): void
    {
        $plain = new HmacSignature('X-Signature', 'secret');
        $stamped = new HmacSignature('X-Signature', ['secret', 'previous'], 'sha512', 'v1=', HmacEncoding::Base64, 'X-Delivery', 'X-Timestamp', clock: $this->clock);

        $headers = $plain->sign('ignored', $this->clock->now(), self::BODY);

        self::assertSame(['X-Signature' => 'sha256=' . hash_hmac('sha256', self::BODY, 'secret')], $headers);
        self::assertNull($plain->verify(self::request($headers), self::BODY)->deliveryId);

        $headers = $stamped->sign('dlv_1', $this->clock->now(), self::BODY);
        $timestamp = (string) $this->clock->now()->getTimestamp();

        self::assertSame(
            [
                'X-Signature' => 'v1=' . base64_encode(hash_hmac('sha512', $timestamp . '.' . self::BODY, 'secret', true)),
                'X-Delivery' => 'dlv_1',
                'X-Timestamp' => $timestamp,
            ],
            $headers,
        );

        $verified = $stamped->verify(self::request($headers), self::BODY);

        self::assertSame('dlv_1', $verified->deliveryId);
        self::assertEquals($this->clock->now(), $verified->signedAt);

        // A webhook signed with the previous secret is still accepted.
        $previous = new HmacSignature('X-Signature', 'previous', 'sha512', 'v1=', HmacEncoding::Base64, 'X-Delivery', 'X-Timestamp', clock: $this->clock);
        $stamped->verify(self::request($previous->sign('dlv_2', $this->clock->now(), self::BODY)), self::BODY);
    }

    /**
     * @param array<string, string|null> $changes
     */
    #[DataProvider('tamperedHmacWebhooks')]
    public function testATamperedHmacWebhookIsRefused(array $changes, string $body, string $message): void
    {
        $scheme = new HmacSignature('X-Signature', 'secret', idHeader: 'X-Delivery', timestampHeader: 'X-Timestamp', clock: $this->clock);
        $headers = array_filter([...$scheme->sign('dlv_1', $this->clock->now(), self::BODY), ...$changes], static fn(?string $value): bool => $value !== null);

        $this->expectException(InvalidSignature::class);
        $this->expectExceptionMessage($message);

        $scheme->verify(self::request($headers), $body);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string, string}>
     */
    public static function tamperedHmacWebhooks(): iterable
    {
        yield 'another body' => [[], '{}', 'The signature of the webhook is not valid.'];
        yield 'another timestamp' => [['X-Timestamp' => '1767268801'], self::BODY, 'The signature of the webhook is not valid.'];
        yield 'no signature' => [['X-Signature' => null], self::BODY, 'The X-Signature header is required.'];
        yield 'another prefix' => [['X-Signature' => 'sha1=abc'], self::BODY, 'The X-Signature header is required.'];
        yield 'no timestamp' => [['X-Timestamp' => null], self::BODY, 'The X-Timestamp header is required.'];
        yield 'old timestamp' => [['X-Timestamp' => '1700000000'], self::BODY, 'The webhook was signed too long ago, or in the future.'];
    }

    public function testAnHmacSchemeNeedsARealAlgorithmAndASecret(): void
    {
        try {
            new HmacSignature('X-Signature', 'secret', 'sha-nope');
            self::fail('The algorithm should be refused.');
        } catch (ConfigurationError $error) {
            self::assertSame('"sha-nope" is not an HMAC algorithm.', $error->getMessage());
        }

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('At least one webhook secret is required.');

        new HmacSignature('X-Signature', ['']);
    }

    public function testSchemesAreInterchangeable(): void
    {
        foreach ([new StandardWebhooks('secret', clock: $this->clock), new HmacSignature('X-Signature', 'secret', clock: $this->clock)] as $scheme) {
            self::assertInstanceOf(SignatureScheme::class, $scheme);
            $scheme->verify(self::request($scheme->sign('msg_1', $this->clock->now(), self::BODY)), self::BODY);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(array $headers): RequestInterface
    {
        $request = new Request('https://bank.example.test/webhooks/payments', 'POST');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
