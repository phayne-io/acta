<?php

declare(strict_types=1);

namespace Acta\Webhook\Incoming;

use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\SystemClock;
use Acta\EventSourcing\TransactionManager;
use Acta\Webhook\Exception\ConfigurationError;
use Acta\Webhook\Exception\InvalidSignature;
use Acta\Webhook\Exception\UnprocessableWebhook;
use Closure;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Primary adapter receiving webhooks: `POST /webhooks/{source}`.
 *
 * For each request: the sender is identified from the URL; its signature is verified
 * on the raw body; a delivery already handled is acknowledged without being handled
 * again; the translator of the sender turns the webhook into commands, which are
 * dispatched on the command bus.
 *
 * | Outcome | Answer |
 * | --- | --- |
 * | Handled, or already handled, or of no interest | 204 |
 * | Unknown sender | 404 |
 * | Not a POST | 405 |
 * | Signature missing, wrong or too old | 401 |
 * | Body too large | 413 |
 * | Body that is not a JSON object or array | 400 |
 * | Refused by the translator | 422 |
 * | Failure while handling | the exception is rethrown: answer 5xx so that the sender retries |
 *
 * Refusals are answered with a small problem document (RFC 9457).
 */
final readonly class WebhookReceiver implements RequestHandlerInterface
{
    /** @var array<string, WebhookSource> */
    private array $sources;

    /** @var Closure(ServerRequestInterface): string */
    private Closure $sourceOf;

    /**
     * @param list<WebhookSource>                            $sources      the senders accepted
     * @param DeliveryLog                                    $deliveries   remembers handled deliveries; share it between processes in production
     * @param TransactionManager|null                        $transactions when given, the commands of a webhook and the record of
     *                                                                     its delivery are committed together or not at all
     * @param int                                            $retention    seconds a handled delivery is remembered; longer than the
     *                                                                     retry period of the senders
     * @param int                                            $maxBodySize  largest body accepted, in bytes
     * @param (Closure(ServerRequestInterface): string)|null $sourceOf     how to find the name of the sender in a request; by default
     *                                                                     its `source` attribute, else the last segment of its path
     *
     * @throws ConfigurationError when two senders have the same name
     */
    public function __construct(
        private CommandBus $commands,
        array $sources,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private DeliveryLog $deliveries = new InMemoryDeliveryLog(),
        private ?TransactionManager $transactions = null,
        private int $retention = 604_800,
        private int $maxBodySize = 1_048_576,
        private ClockInterface $clock = new SystemClock(),
        private LoggerInterface $logger = new NullLogger(),
        ?Closure $sourceOf = null,
    ) {
        $named = [];

        foreach ($sources as $source) {
            if (isset($named[$source->name])) {
                throw new ConfigurationError(sprintf('Two webhook sources are named "%s".', $source->name));
            }

            $named[$source->name] = $source;
        }

        $this->sources = $named;
        $this->sourceOf = $sourceOf ?? static function (ServerRequestInterface $request): string {
            $attribute = $request->getAttribute('source');

            return is_string($attribute) ? $attribute : basename(rtrim($request->getUri()->getPath(), '/'));
        };
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = ($this->sourceOf)($request);
        $source = $this->sources[$name] ?? null;

        if ($source === null) {
            return $this->refuse(404, 'Not Found', 'No webhook is expected here.');
        }

        if ($request->getMethod() !== 'POST') {
            return $this->refuse(405, 'Method Not Allowed', 'Webhooks are sent with POST.')->withHeader('Allow', 'POST');
        }

        if (($request->getBody()->getSize() ?? 0) > $this->maxBodySize) {
            return $this->refuse(413, 'Content Too Large', sprintf('Webhooks are limited to %d bytes.', $this->maxBodySize));
        }

        $body = (string) $request->getBody();

        if (strlen($body) > $this->maxBodySize) {
            return $this->refuse(413, 'Content Too Large', sprintf('Webhooks are limited to %d bytes.', $this->maxBodySize));
        }

        try {
            $signature = $source->signature->verify($request, $body);
        } catch (InvalidSignature $exception) {
            $this->logger->warning('Refused a webhook of {source}: {reason}', ['source' => $name, 'reason' => $exception->getMessage()]);

            return $this->refuse(401, 'Unauthorized', $exception->getMessage());
        }

        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            return $this->refuse(400, 'Bad Request', 'The body of a webhook must be a JSON object or array.');
        }

        $headers = [];

        foreach ($request->getHeaders() as $header => $values) {
            $headers[strtolower((string) $header)] = implode(', ', $values);
        }

        // Without identifier from the sender, the same bytes are the same delivery.
        $webhook = new IncomingWebhook(
            $name,
            $signature->deliveryId ?? hash('sha256', $body),
            $payload,
            $body,
            $headers,
            $signature->signedAt,
        );

        try {
            $handled = $this->transactions === null
                ? $this->process($source, $webhook)
                : $this->transactions->transactional(fn(): ?int => $this->process($source, $webhook));
        } catch (UnprocessableWebhook $exception) {
            // The record of the delivery is kept: the same webhook will not be translated again.
            $this->logger->warning('Webhook {id} of {source} cannot be processed: {reason}', [
                'id' => $webhook->id,
                'source' => $name,
                'reason' => $exception->getMessage(),
            ]);

            return $this->refuse(422, 'Unprocessable Content', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->deliveries->release($name, $webhook->id);
            $this->logger->error('Handling webhook {id} of {source} failed: {reason}', [
                'id' => $webhook->id,
                'source' => $name,
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger->info(
            $handled === null ? 'Webhook {id} of {source} was already handled.' : 'Handled webhook {id} of {source}: {commands} command(s).',
            ['id' => $webhook->id, 'source' => $name, 'commands' => $handled ?? 0],
        );

        return $this->responses->createResponse(204);
    }

    /**
     * @return int|null the number of commands dispatched, or null when the delivery was already handled
     */
    private function process(WebhookSource $source, IncomingWebhook $webhook): ?int
    {
        $now = $this->clock->now();

        if (!$this->deliveries->claim($webhook->source, $webhook->id, $now, $now->modify(sprintf('+%d seconds', $this->retention)))) {
            return null;
        }

        $metadata = new Metadata(['channel' => 'webhook', 'webhook_source' => $webhook->source, 'webhook_id' => $webhook->id]);
        $dispatched = 0;

        foreach ($source->translator->translate($webhook) as $command) {
            $this->commands->dispatch($command, $metadata);
            ++$dispatched;
        }

        return $dispatched;
    }

    private function refuse(int $status, string $title, string $detail): ResponseInterface
    {
        $problem = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail];

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/problem+json')
            ->withBody($this->streams->createStream(json_encode($problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }
}
