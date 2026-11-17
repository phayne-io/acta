<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use Acta\EventSourcing\SystemClock;
use Acta\Webhook\Signature\StandardWebhooks;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Secondary adapter sending the deliveries of the queue to their subscribers.
 *
 * Each delivery is POSTed as JSON, signed with the secret of its subscription
 * following {@see StandardWebhooks}. A 2xx answer acknowledges it. Anything else, a
 * network failure included, schedules another attempt as the {@see RetryPolicy} says,
 * until it gives up and the delivery becomes a dead letter. A `410 Gone` answer, or a
 * subscription that no longer exists, ends it at once.
 *
 * Deliveries are at-least-once: a subscriber may receive the same one twice, with the
 * same `webhook-id`, and is expected to deduplicate on it.
 *
 * Give it an HTTP client with short timeouts that does not follow redirects.
 */
final readonly class WebhookSender
{
    /**
     * @param int    $reservation seconds a worker has to finish an attempt before the delivery is handed
     *                            to another one; longer than the timeout of the HTTP client
     * @param string $userAgent   `User-Agent` header of the requests
     */
    public function __construct(
        private DeliveryQueue $queue,
        private SubscriptionRepository $subscriptions,
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private RetryPolicy $retries = new RetryPolicy(),
        private ClockInterface $clock = new SystemClock(),
        private LoggerInterface $logger = new NullLogger(),
        private int $reservation = 60,
        private string $userAgent = 'Acta-Webhooks/1',
    ) {}

    /**
     * Attempts the deliveries that are due.
     *
     * @param int $limit how many deliveries to attempt at most
     */
    public function deliverDue(int $limit = 100): DeliveryReport
    {
        $now = $this->clock->now();
        $counts = ['delivered' => 0, 'retried' => 0, 'dead' => 0];

        foreach ($this->queue->reserve($now, $limit, $now->modify(sprintf('+%d seconds', $this->reservation))) as $delivery) {
            ++$counts[$this->attempt($delivery)];
        }

        return new DeliveryReport(...$counts);
    }

    /**
     * @return 'delivered'|'retried'|'dead'
     */
    private function attempt(OutgoingDelivery $delivery): string
    {
        $context = ['delivery' => $delivery->id, 'type' => $delivery->eventType, 'subscription' => $delivery->subscriptionId, 'attempt' => $delivery->attempts];
        $subscription = $this->subscriptions->find($delivery->subscriptionId);

        if ($subscription === null) {
            $this->queue->dead($delivery->id, null, 'The subscription no longer exists.');
            $this->logger->notice('Gave up on webhook {delivery}: its subscription {subscription} no longer exists.', $context);

            return 'dead';
        }

        $request = $this->requests->createRequest('POST', $subscription->url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', $this->userAgent)
            ->withBody($this->streams->createStream($delivery->body));

        foreach (new StandardWebhooks($subscription->secret)->sign($delivery->id, $this->clock->now(), $delivery->body) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $status = $this->http->sendRequest($request)->getStatusCode();
            $error = sprintf('The subscriber answered with status %d.', $status);
        } catch (ClientExceptionInterface $exception) {
            $status = null;
            $error = 'The subscriber could not be reached: ' . $exception->getMessage();
        }

        if ($status !== null && $status >= 200 && $status < 300) {
            $this->queue->delivered($delivery->id, $status);
            $this->logger->info('Delivered webhook {delivery} ({type}) to {subscription} on attempt {attempt}.', $context);

            return 'delivered';
        }

        $delay = $status === 410 ? null : $this->retries->delayAfter($delivery->attempts);

        if ($delay === null) {
            $this->queue->dead($delivery->id, $status, $error);
            $this->logger->error('Gave up on webhook {delivery} ({type}) to {subscription} after {attempt} attempt(s): {error}', [...$context, 'error' => $error]);

            return 'dead';
        }

        $this->queue->retryAt($delivery->id, $this->clock->now()->modify(sprintf('+%d seconds', $delay)), $status, $error);
        $this->logger->warning('Webhook {delivery} ({type}) to {subscription} failed on attempt {attempt}, retrying in {delay} s: {error}', [
            ...$context,
            'delay' => $delay,
            'error' => $error,
        ]);

        return 'retried';
    }
}
