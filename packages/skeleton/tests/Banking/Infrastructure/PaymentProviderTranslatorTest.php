<?php

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use Acta\Webhook\Exception\UnprocessableWebhook;
use Acta\Webhook\Incoming\IncomingWebhook;
use App\Banking\Application\Command\DepositMoney;
use App\Banking\Domain\AccountId;
use App\Banking\Infrastructure\Primary\Webhook\PaymentProviderTranslator;
use App\Banking\Infrastructure\Primary\Webhook\PaymentsWebhookSource;
use Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the webhooks of the payment provider mean to the bank.
 */
#[CoversClass(PaymentProviderTranslator::class)]
final class PaymentProviderTranslatorTest extends TestCase
{
    public function testASucceededPaymentIsADeposit(): void
    {
        $account = AccountId::generate();

        $commands = $this->translate([
            'type' => 'payment.succeeded',
            'data' => ['id' => 'pay_1', 'account' => $account->value, 'amount' => '12000', 'currency' => 'EUR'],
        ]);

        self::assertEquals([new DepositMoney($account, Money::EUR(12000), 'pay_1')], $commands);
    }

    public function testEventsOfNoInterestAreAcknowledged(): void
    {
        self::assertSame([], $this->translate(['type' => 'ping']));
        self::assertSame([], $this->translate(['type' => 'payment.created']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('unreadablePayloads')]
    public function testWhatIsNotUnderstoodIsRefused(array $payload): void
    {
        $this->expectException(UnprocessableWebhook::class);

        $this->translate($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadablePayloads(): iterable
    {
        $payment = ['id' => 'pay_1', 'account' => AccountId::generate()->value, 'amount' => '12000', 'currency' => 'EUR'];

        yield 'an unknown event' => [['type' => 'refund.created']];
        yield 'no type' => [['data' => $payment]];
        yield 'no data' => [['type' => 'payment.succeeded']];
        yield 'an amount that is not in minor units' => [['type' => 'payment.succeeded', 'data' => ['amount' => '120.00'] + $payment]];
        yield 'an amount that is a number' => [['type' => 'payment.succeeded', 'data' => ['amount' => 12000] + $payment]];
        yield 'no currency' => [['type' => 'payment.succeeded', 'data' => ['currency' => ''] + $payment]];
        yield 'no account' => [['type' => 'payment.succeeded', 'data' => ['account' => ' '] + $payment]];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<object>
     */
    private function translate(array $payload): array
    {
        $webhook = new IncomingWebhook(PaymentsWebhookSource::NAME, 'msg_1', $payload, json_encode($payload, JSON_THROW_ON_ERROR));

        return array_values([...new PaymentProviderTranslator()->translate($webhook)]);
    }
}
