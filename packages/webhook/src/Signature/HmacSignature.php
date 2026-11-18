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
 * An HMAC of the body in a header, the scheme of GitHub and of many others.
 *
 *     // GitHub
 *     new HmacSignature('X-Hub-Signature-256', $secret, idHeader: 'X-GitHub-Delivery')
 *
 * When a timestamp header is configured, the signature covers `{timestamp}.{body}` and
 * old webhooks are refused, which prevents replays. Without it only the body is
 * covered: deduplicate on the delivery identifier, and prefer a scheme with timestamps
 * whenever the sender offers one.
 */
final readonly class HmacSignature implements SignatureScheme
{
    /** @var non-empty-list<string> */
    private array $secrets;

    /**
     * @param string              $header          header carrying the signature
     * @param string|list<string> $secrets         secret(s) shared with the other party; several to rotate them
     * @param string              $algorithm       hash algorithm of the HMAC
     * @param string              $prefix          what precedes the signature in the header, such as `sha256=`
     * @param string|null         $idHeader        header carrying the identifier of the delivery, if any
     * @param string|null         $timestampHeader header carrying the Unix time of the signature, if any
     * @param int                 $tolerance       seconds a timestamp may differ from the clock, in either direction
     *
     * @throws ConfigurationError when no secret is given or the algorithm does not exist
     */
    public function __construct(
        private string $header,
        #[SensitiveParameter]
        string|array $secrets,
        private string $algorithm = 'sha256',
        private string $prefix = 'sha256=',
        private HmacEncoding $encoding = HmacEncoding::Hex,
        private ?string $idHeader = null,
        private ?string $timestampHeader = null,
        private int $tolerance = 300,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $secrets = array_values(array_filter((array) $secrets, static fn(string $secret): bool => $secret !== ''));

        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new ConfigurationError(sprintf('"%s" is not an HMAC algorithm.', $algorithm));
        }

        $this->secrets = $secrets !== [] ? $secrets : throw new ConfigurationError('At least one webhook secret is required.');
    }

    #[Override]
    public function verify(RequestInterface $request, string $body): VerifiedSignature
    {
        $received = $request->getHeaderLine($this->header);

        if ($received === '' || !str_starts_with($received, $this->prefix)) {
            throw new InvalidSignature(sprintf('The %s header is required.', $this->header));
        }

        $signedAt = null;
        $signed = $body;

        if ($this->timestampHeader !== null) {
            $timestamp = $request->getHeaderLine($this->timestampHeader);

            if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
                throw new InvalidSignature(sprintf('The %s header is required.', $this->timestampHeader));
            }

            if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > $this->tolerance) {
                throw new InvalidSignature('The webhook was signed too long ago, or in the future.');
            }

            $signedAt = new DateTimeImmutable('@' . $timestamp);
            $signed = $timestamp . '.' . $body;
        }

        $received = substr($received, strlen($this->prefix));

        if (!array_any($this->secrets, fn(string $secret): bool => hash_equals($this->signature($secret, $signed), $received))) {
            throw new InvalidSignature('The signature of the webhook is not valid.');
        }

        $id = $this->idHeader === null ? '' : $request->getHeaderLine($this->idHeader);

        return new VerifiedSignature($id === '' ? null : $id, $signedAt);
    }

    #[Override]
    public function sign(string $deliveryId, DateTimeImmutable $signedAt, string $body): array
    {
        $timestamp = (string) $signedAt->getTimestamp();
        $signed = $this->timestampHeader === null ? $body : $timestamp . '.' . $body;

        return [
            $this->header => $this->prefix . $this->signature($this->secrets[0], $signed),
            ...($this->idHeader === null ? [] : [$this->idHeader => $deliveryId]),
            ...($this->timestampHeader === null ? [] : [$this->timestampHeader => $timestamp]),
        ];
    }

    private function signature(string $secret, string $signed): string
    {
        return $this->encoding->encode(hash_hmac($this->algorithm, $signed, $secret, true));
    }
}
