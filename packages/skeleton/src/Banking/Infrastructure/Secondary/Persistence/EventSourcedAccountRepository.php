<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Secondary\Persistence;

use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\SystemClock;
use App\Banking\Domain\Account;
use App\Banking\Domain\AccountId;
use App\Banking\Domain\AccountRepository;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Secondary adapter: keeps accounts as streams of events in the event store.
 */
final readonly class EventSourcedAccountRepository implements AccountRepository
{
    /** Category of the streams of accounts: an account is the stream `banking_account-<id>`. */
    public const string CATEGORY = 'banking_account';

    /** @var AggregateRepository<Account> */
    private AggregateRepository $accounts;

    public function __construct(
        EventStore $eventStore,
        EnvelopeFactory $envelopes = new EnvelopeFactory(),
        ClockInterface $clock = new SystemClock(),
    ) {
        $this->accounts = new AggregateRepository(Account::class, self::CATEGORY, $eventStore, $envelopes, clock: $clock);
    }

    #[Override]
    public function get(AccountId $id): Account
    {
        return $this->accounts->get($id);
    }

    #[Override]
    public function save(Account $account): void
    {
        $this->accounts->save($account);
    }
}
