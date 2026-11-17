<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

/**
 * Checks an outgoing webhook against its public description before it is sent.
 *
 * {@see \Acta\Webhook\Bridge\ContractPayloadValidator} does it against the `webhooks`
 * section of an OpenAPI contract.
 */
interface PayloadValidator
{
    /**
     * @param string               $eventType type of the integration event
     * @param array<string, mixed> $body      the decoded body about to be sent
     *
     * @return list<string> what is wrong; empty when the body matches its description
     */
    public function validate(string $eventType, array $body): array;
}
