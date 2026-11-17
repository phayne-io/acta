<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

/**
 * Converts stored messages written with an old schema to the current one.
 *
 * Upcasters run on the stored form, before the message class is instantiated, so the
 * domain only ever sees the latest shape of each event. As in Prooph, one stored message
 * can be turned into none, one or several messages.
 *
 * ```php
 * final class AccountOpenedV1ToV2 implements Upcaster
 * {
 *     public function upcast(SerializedMessage $message): iterable
 *     {
 *         if ($message->name !== 'banking.account-opened' || $message->version !== 1) {
 *             return [$message];
 *         }
 *
 *         return [$message->withPayload([...$message->payload, 'currency' => 'EUR'], version: 2)];
 *     }
 * }
 * ```
 */
interface Upcaster
{
    /**
     * @return iterable<SerializedMessage> the message untouched, its replacement(s), or nothing to drop it
     */
    public function upcast(SerializedMessage $message): iterable;
}
