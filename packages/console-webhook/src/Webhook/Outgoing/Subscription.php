<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use Acta\Webhook\Exception\ConfigurationError;
use SensitiveParameter;

/**
 * An endpoint that asked to receive some integration events.
 */
final readonly class Subscription
{
    /**
     * @param string       $id            stable identifier of the subscription
     * @param string       $url           where to POST the webhooks; `https`
     * @param string       $secret        secret the webhooks are signed with, see {@see \Acta\Webhook\Signature\StandardWebhooks}
     * @param list<string> $eventTypes    event types wanted: exact names, `prefix.*` patterns, or `*` for all
     * @param bool         $allowInsecure accepts an `http` URL; for local development only
     *
     * @throws ConfigurationError when the URL is not acceptable
     */
    public function __construct(
        public string $id,
        public string $url,
        #[SensitiveParameter]
        public string $secret,
        public array $eventTypes = ['*'],
        bool $allowInsecure = false,
    ) {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string(parse_url($url, PHP_URL_HOST)) || !in_array($scheme, $allowInsecure ? ['https', 'http'] : ['https'], true)) {
            throw new ConfigurationError(sprintf('"%s" is not an acceptable webhook URL: an https URL is expected.', $url));
        }
    }

    /**
     * Tells whether the subscription wants an event type.
     */
    public function wants(string $eventType): bool
    {
        return array_any(
            $this->eventTypes,
            static fn(string $pattern): bool => $pattern === '*'
                || $pattern === $eventType
                || (str_ends_with($pattern, '.*') && str_starts_with($eventType, substr($pattern, 0, -1))),
        );
    }
}
