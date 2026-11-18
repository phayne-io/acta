<?php

declare(strict_types=1);

namespace Acta\Webhook\Incoming;

use DateTimeImmutable;

/**
 * A webhook that was received and whose signature was verified.
 */
final readonly class IncomingWebhook
{
    /**
     * @param string                 $source  name of the sender, as configured
     * @param string                 $id      identifier of the delivery, the same when the sender retries
     * @param array<mixed>           $payload decoded JSON body
     * @param string                 $body    body as received
     * @param array<string, string>  $headers headers of the request, keyed by lower-case name
     * @param DateTimeImmutable|null $sentAt  when the sender signed it, if the scheme says
     */
    public function __construct(
        public string $source,
        public string $id,
        public array $payload,
        public string $body,
        public array $headers = [],
        public ?DateTimeImmutable $sentAt = null,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
