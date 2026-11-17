<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Postgres;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\MetadataMatcher;
use Acta\EventSourcing\EventStore\Operator;
use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Serializer\SerializedMessage;
use Override;
use PDO;
use PDOException;

/**
 * Event store on PostgreSQL, through PDO. See {@see PostgresSchema} for the table.
 *
 * Optimistic concurrency relies on the unique `(stream_name, stream_version)`
 * constraint: two writers targeting the same version cannot both succeed, without any
 * explicit lock. Reads of the global log are ordered by `(transaction_id, position)` and
 * stop before the oldest transaction still in progress, so a projection never skips an
 * event committed late by a slow transaction.
 *
 * The connection must throw exceptions on errors, which is the default since PHP 8.0.
 */
final readonly class PostgresEventStore implements EventStore
{
    private const int PAGE_SIZE = 1000;

    private const int MAX_STREAM_VERSION = 2_147_483_647;

    private const string UNIQUE_VIOLATION = '23505';

    private const string COLUMNS = <<<'SQL'
        position, transaction_id::text AS transaction_id, event_id, stream_name, stream_version, event_name,
        schema_version, payload, metadata,
        to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"') AS created_at
        SQL;

    private string $table;

    /**
     * @param string      $table         name of the events table, as given to {@see PostgresSchema}
     * @param string|null $notifyChannel channel notified on each append, which lets projections wake up
     *                                   through a {@see NotificationWaiter}; `null` disables notifications
     */
    public function __construct(
        private PDO $pdo,
        private MessageSerializer $serializer = new MessageSerializer(),
        string $table = 'event_store',
        private ?string $notifyChannel = 'acta_events',
    ) {
        $this->table = PostgresSchema::identifier($table);
    }

    #[Override]
    public function append(StreamName $stream, ExpectedVersion $expectedVersion, Envelope ...$events): array
    {
        if ($events === []) {
            return [];
        }

        $currentVersion = $this->streamVersion($stream);

        if (!$expectedVersion->isSatisfiedBy($currentVersion)) {
            throw ConcurrencyConflict::versionMismatch($stream, $expectedVersion, $currentVersion);
        }

        $version = $currentVersion;
        $versions = [];
        $rows = [];
        $parameters = [];

        foreach ($events as $envelope) {
            $serialized = $this->serializer->serialize($envelope);
            $versions[++$version] = $envelope;
            $rows[] = '(?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::timestamptz)';

            array_push(
                $parameters,
                $serialized->id,
                $stream->value,
                $stream->category,
                $version,
                $serialized->name,
                $serialized->version,
                Row::encode($serialized->payload),
                Row::encode($serialized->metadata),
                $serialized->createdAt,
            );
        }

        $sql = sprintf(
            'INSERT INTO %s (event_id, stream_name, stream_category, stream_version, event_name, schema_version, '
            . 'payload, metadata, created_at) VALUES %s '
            . 'RETURNING stream_version, position, transaction_id::text AS transaction_id',
            $this->table,
            implode(', ', $rows),
        );

        try {
            $inserted = $this->fetchAll($sql, $parameters);
        } catch (PDOException $exception) {
            // Another writer took the version between our check and our insert.
            if ($exception->getCode() === self::UNIQUE_VIOLATION && str_contains($exception->getMessage(), '_stream_version_key')) {
                throw ConcurrencyConflict::versionMismatch(
                    $stream,
                    $expectedVersion,
                    $currentVersion + 1,
                    $exception,
                );
            }

            throw $exception;
        }

        if ($this->notifyChannel !== null) {
            $this->fetchAll('SELECT pg_notify(?, ?)', [$this->notifyChannel, $stream->category]);
        }

        $recorded = [];

        foreach ($inserted as $row) {
            $insertedVersion = Row::int($row, 'stream_version');

            $recorded[$insertedVersion] = new RecordedEvent(
                $versions[$insertedVersion],
                $stream,
                $insertedVersion,
                new Position(Row::int($row, 'transaction_id'), Row::int($row, 'position')),
            );
        }

        ksort($recorded);

        return array_values($recorded);
    }

    #[Override]
    public function delete(StreamName $stream): void
    {
        $this->fetchAll("DELETE FROM {$this->table} WHERE stream_name = ?", [$stream->value]);
    }

    #[Override]
    public function load(
        StreamName $stream,
        int $fromVersion = 1,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable {
        [$conditions, $parameters] = self::matcherSql($matcher);
        $where = implode(' AND ', ['stream_name = ?', 'stream_version >= ?', ...$conditions]);
        $next = max(1, $fromVersion);

        foreach (self::pages($count) as $limit) {
            $rows = $this->fetchAll(
                sprintf('SELECT %s FROM %s WHERE %s ORDER BY stream_version ASC LIMIT %d', self::COLUMNS, $this->table, $where, $limit),
                [$stream->value, $next, ...$parameters],
            );

            foreach ($rows as $row) {
                yield from $this->recorded($row);

                $next = Row::int($row, 'stream_version') + 1;
            }

            if (count($rows) < $limit) {
                return;
            }
        }
    }

    #[Override]
    public function loadReverse(
        StreamName $stream,
        ?int $fromVersion = null,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable {
        [$conditions, $parameters] = self::matcherSql($matcher);
        $where = implode(' AND ', ['stream_name = ?', 'stream_version <= ?', ...$conditions]);
        $next = min($fromVersion ?? self::MAX_STREAM_VERSION, self::MAX_STREAM_VERSION);

        foreach (self::pages($count) as $limit) {
            $rows = $this->fetchAll(
                sprintf('SELECT %s FROM %s WHERE %s ORDER BY stream_version DESC LIMIT %d', self::COLUMNS, $this->table, $where, $limit),
                [$stream->value, $next, ...$parameters],
            );

            foreach ($rows as $row) {
                yield from $this->recorded($row);

                $next = Row::int($row, 'stream_version') - 1;
            }

            if (count($rows) < $limit) {
                return;
            }
        }
    }

    #[Override]
    public function read(Selector $selector, ?Position $after = null, ?int $count = null): iterable
    {
        [$conditions, $parameters] = self::selectorSql($selector);

        $where = implode(' AND ', [
            '(transaction_id, position) > (?::xid8, ?::bigint)',
            'transaction_id < pg_snapshot_xmin(pg_current_snapshot())',
            ...$conditions,
        ]);

        $position = $after ?? Position::start();

        foreach (self::pages($count) as $limit) {
            $rows = $this->fetchAll(
                sprintf('SELECT %s FROM %s WHERE %s ORDER BY transaction_id, position LIMIT %d', self::COLUMNS, $this->table, $where, $limit),
                [$position->transaction, $position->sequence, ...$parameters],
            );

            foreach ($rows as $row) {
                yield from $this->recorded($row);

                $position = new Position(Row::int($row, 'transaction_id'), Row::int($row, 'position'));
            }

            if (count($rows) < $limit) {
                return;
            }
        }
    }

    #[Override]
    public function hasStream(StreamName $stream): bool
    {
        return $this->fetchAll("SELECT 1 FROM {$this->table} WHERE stream_name = ? LIMIT 1", [$stream->value]) !== [];
    }

    #[Override]
    public function streamVersion(StreamName $stream): int
    {
        $rows = $this->fetchAll(
            "SELECT COALESCE(MAX(stream_version), 0) AS version FROM {$this->table} WHERE stream_name = ?",
            [$stream->value],
        );

        return $rows === [] ? 0 : Row::int($rows[0], 'version');
    }

    #[Override]
    public function streamNames(?string $category = null, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->fetchAll(
            sprintf(
                'SELECT DISTINCT stream_name COLLATE "C" AS stream_name FROM %s %s ORDER BY stream_name LIMIT %d OFFSET %d',
                $this->table,
                $category === null ? '' : 'WHERE stream_category = ?',
                max(0, $limit),
                max(0, $offset),
            ),
            $category === null ? [] : [$category],
        );

        return array_map(
            static fn(array $row): StreamName => StreamName::fromString(Row::string($row, 'stream_name')),
            $rows,
        );
    }

    #[Override]
    public function categoryNames(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT DISTINCT stream_category COLLATE "C" AS category FROM %s ORDER BY category LIMIT %d OFFSET %d',
            $this->table,
            max(0, $limit),
            max(0, $offset),
        ));

        return array_map(static fn(array $row): string => Row::string($row, 'category'), $rows);
    }

    /**
     * Yields the size of each page to fetch so that at most `$count` rows are read in
     * total, in pages of bounded size to keep memory flat on long streams.
     *
     * @return iterable<int>
     */
    private static function pages(?int $count): iterable
    {
        $remaining = $count;

        while ($remaining === null || $remaining > 0) {
            $limit = $remaining === null ? self::PAGE_SIZE : min(self::PAGE_SIZE, $remaining);

            yield $limit;

            if ($remaining !== null) {
                $remaining -= $limit;
            }
        }
    }

    /**
     * @return array{list<string>, list<scalar>} SQL conditions and their parameters
     */
    private static function selectorSql(Selector $selector): array
    {
        $conditions = [];
        $parameters = [];
        $sources = [];

        foreach (['stream_name' => $selector->streams, 'stream_category' => $selector->categories] as $column => $values) {
            if ($values !== []) {
                $sources[] = sprintf('%s IN (%s)', $column, self::placeholders($values));
                array_push($parameters, ...$values);
            }
        }

        if ($sources !== []) {
            $conditions[] = '(' . implode(' OR ', $sources) . ')';
        }

        if ($selector->messageNames !== []) {
            $conditions[] = sprintf('event_name IN (%s)', self::placeholders($selector->messageNames));
            array_push($parameters, ...$selector->messageNames);
        }

        [$matcherConditions, $matcherParameters] = self::matcherSql($selector->matcher);

        return [[...$conditions, ...$matcherConditions], [...$parameters, ...$matcherParameters]];
    }

    /**
     * Translates a metadata matcher to SQL, with the semantics of {@see Operator::test()}.
     *
     * @return array{list<string>, list<scalar>} SQL conditions and their parameters
     */
    private static function matcherSql(?MetadataMatcher $matcher): array
    {
        $conditions = [];
        $parameters = [];

        foreach ($matcher->conditions ?? [] as ['field' => $field, 'operator' => $operator, 'value' => $value]) {
            $json = "COALESCE(metadata -> ?::text, 'null'::jsonb)";

            switch ($operator) {
                case Operator::Equals:
                case Operator::NotEquals:
                    $conditions[] = sprintf('%s %s ?::jsonb', $json, $operator === Operator::Equals ? '=' : '<>');
                    array_push($parameters, $field, Row::encode($value));

                    break;

                case Operator::In:
                case Operator::NotIn:
                    $values = array_values((array) $value);

                    if ($values === []) {
                        $conditions[] = $operator === Operator::In ? 'FALSE' : 'TRUE';

                        break;
                    }

                    $conditions[] = sprintf(
                        '%s %s (%s)',
                        $json,
                        $operator === Operator::In ? 'IN' : 'NOT IN',
                        self::placeholders($values, '?::jsonb'),
                    );
                    array_push($parameters, $field, ...array_map(Row::encode(...), $values));

                    break;

                case Operator::Regex:
                    $conditions[] = "CASE WHEN jsonb_typeof(metadata -> ?::text) = 'string' "
                        . 'THEN (metadata ->> ?::text) ~ ?::text ELSE FALSE END';
                    array_push($parameters, $field, $field, is_string($value) ? $value : '');

                    break;

                default:
                    $numeric = is_int($value) || is_float($value);
                    $conditions[] = sprintf(
                        'CASE WHEN jsonb_typeof(metadata -> ?::text) = \'%s\' THEN (metadata ->> ?::text)%s %s ?::%s ELSE FALSE END',
                        $numeric ? 'number' : 'string',
                        $numeric ? '::numeric' : ' COLLATE "C"',
                        $operator->value,
                        $numeric ? 'numeric' : 'text',
                    );
                    array_push($parameters, $field, $field, is_int($value) || is_float($value) || is_string($value) ? $value : '');
            }
        }

        return [$conditions, $parameters];
    }

    /**
     * @param array<mixed> $values
     */
    private static function placeholders(array $values, string $placeholder = '?'): string
    {
        return implode(', ', array_fill(0, count($values), $placeholder));
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<RecordedEvent>
     */
    private function recorded(array $row): array
    {
        $message = new SerializedMessage(
            Row::string($row, 'event_id'),
            Row::string($row, 'event_name'),
            Row::int($row, 'schema_version'),
            Row::jsonObject($row, 'payload'),
            Row::jsonObject($row, 'metadata'),
            Row::string($row, 'created_at'),
        );

        $stream = StreamName::fromString(Row::string($row, 'stream_name'));
        $version = Row::int($row, 'stream_version');
        $position = new Position(Row::int($row, 'transaction_id'), Row::int($row, 'position'));

        $record = static fn(Envelope $envelope): RecordedEvent => new RecordedEvent($envelope, $stream, $version, $position);

        return $message
            |> $this->serializer->deserialize(...)
            |> (static fn(array $envelopes): array => array_map($record, $envelopes));
    }

    /**
     * @param list<scalar|null> $parameters
     *
     * @return list<array<mixed>>
     */
    private function fetchAll(string $sql, array $parameters = []): array
    {
        return Row::all($this->pdo, $sql, $parameters);
    }
}
