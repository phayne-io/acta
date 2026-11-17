<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Idempotency;

use Acta\Http\Idempotency\IdempotencyStore;
use Acta\Http\Idempotency\InMemoryIdempotencyStore;
use Acta\Http\Testing\IdempotencyStoreContract;
use DateTimeImmutable;
use Override;

final class InMemoryIdempotencyStoreTest extends IdempotencyStoreContract
{
    #[Override]
    protected function createStore(): IdempotencyStore
    {
        return new InMemoryIdempotencyStore();
    }

    public function testCompletingAnUnknownKeyDoesNothing(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->complete('ghost', new \Acta\Http\Idempotency\StoredResponse(200, [], ''));

        self::assertNull($store->acquire('ghost', 'print', new DateTimeImmutable(), new DateTimeImmutable('+1 hour')));
    }
}
