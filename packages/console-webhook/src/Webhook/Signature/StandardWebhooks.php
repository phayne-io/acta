<?php

declare(strict_types=1);

namespace Acta\Webhook\Signature;

use Acta\EventSourcing\SystemClock;
use Acta\Webhook\Exception\ConfigurationError;
use Acta\Webhook\Exception\InvalidSignature;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * The Standard Webhooks signature scheme.
 *
 * Three headers travel with the body: `webhook-id`, `webhook-timestamp` and
 * `webhook-signature`. The signature is the HMAC-SHA256 of `{id}.{timestamp}.{body}`,
 * so that neither the identifier nor the timestamp can be changed, and a webhook
 * captured on the wire cannot be replayed once the tolerance has passed.
 *
 * Several secrets may be given to rotate them without downtime: a received webhook is
 * accepted when any of them verifies it, and a sent one is signed with all of them.
 *
 * @see https://www.standardwebhooks.com/
 */
final readonly class StandardWebhooks implements SignatureScheme
{
    /** @var non-empty-list<string> the secrets, as raw bytes */
    private array $keys;

    /**
     * @param string|list<string> $secrets   secret(s) shared with the other party, either in the `whsec_…`
     *                                       form of the specification or as any other string
     * @param int                 $tolerance seconds a timestamp may differ from the clock, in either direction
     *
     * @throws ConfigurationError when no usable secret is given
     */
    public function __construct(
        #[SensitiveParameter]
        string|array $secrets,
        private int $tolerance = 300,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $keys = [];

        foreach ((array) $secrets as $secret) {
            $key = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : $secret;

            if ($key === false || $key === '') {
                throw new ConfigurationError('A webhook secret is empty or is not valid base64 after its "whsec_" prefix.');
            }

            $keys[] = $key;
        }

        $this->keys = $keys !== [] ? $keys : throw new ConfigurationError('At least one webhook secret is required.');
    }

    /**
     * Returns a new random secret in the `whsec_…` form, to give to a subscriber.
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . base64_encode(random_bytes(32));
    }

    #[Override]
    public function verify(RequestInterface $request, string $body): VerifiedSignature
    {
        $id = $request->getHeaderLine('webhook-id');
        $timestamp = $request->getHeaderLine('webhook-timestamp');
        $signatures = $request->getHeaderLine('webhook-signature');

        if ($id === '' || $signatures === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            throw new InvalidSignature('The webhook-id, webhook-timestamp and webhook-signature headers are required.');
        }

        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > $this->tolerance) {
            throw new InvalidSignature('The webhook was signed too long ago, or in the future.');
        }

        $expected = array_map(fn(string $key): string => self::signature($key, $id, $timestamp, $body), $this->keys);

        foreach (explode(' ', $signatures) as $candidate) {
            // Other versions of the scheme may be listed: only v1, HMAC-SHA256, is known here.
            if (!str_starts_with($candidate, 'v1,')) {
                continue;
            }

            if (array_any($expected, static fn(string $signature): bool => hash_equals($signature, substr($candidate, 3)))) {
                return new VerifiedSignature($id, new DateTimeImmutable('@' . $timestamp));
            }
        }

        throw new InvalidSignature('The signature of the webhook is not valid.');
    }

    #[Override]
    public function sign(string $deliveryId, DateTimeImmutable $signedAt, string $body): array
    {
        $timestamp = (string) $signedAt->getTimestamp();

        return [
            'webhook-id' => $deliveryId,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => implode(' ', array_map(
                static fn(string $key): string => 'v1,' . self::signature($key, $deliveryId, $timestamp, $body),
                $this->keys,
            )),
        ];
    }

    private static function signature(string $key, string $id, string $timestamp, string $body): string
    {
        return base64_encode(hash_hmac('sha256', sprintf('%s.%s.%s', $id, $timestamp, $body), $key, true));
    }
}
