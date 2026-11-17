<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

use Override;

/**
 * Runs several upcasters one after the other.
 *
 * Each upcaster receives the output of the previous one, so a version 1 event can be
 * brought to version 3 by chaining a 1→2 and a 2→3 upcaster.
 */
final readonly class UpcasterChain implements Upcaster
{
    /** @var list<Upcaster> */
    private array $upcasters;

    public function __construct(Upcaster ...$upcasters)
    {
        $this->upcasters = array_values($upcasters);
    }

    #[Override]
    public function upcast(SerializedMessage $message): iterable
    {
        $messages = [$message];

        foreach ($this->upcasters as $upcaster) {
            $upcasted = [];

            foreach ($messages as $current) {
                foreach ($upcaster->upcast($current) as $result) {
                    $upcasted[] = $result;
                }
            }

            $messages = $upcasted;
        }

        return $messages;
    }
}
