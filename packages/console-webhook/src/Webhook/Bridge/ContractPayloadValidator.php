<?php

declare(strict_types=1);

namespace Acta\Webhook\Bridge;

use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\MediaType;
use Acta\Http\OpenApi\Operation;
use Acta\Http\Validation\SchemaValidator;
use Acta\Webhook\Outgoing\PayloadValidator;
use Override;

/**
 * Validates outgoing webhooks against the `webhooks` section of an OpenAPI 3.1
 * contract, with the engine that validates the requests and responses of the API.
 *
 * An event type is described by the webhook of the same name:
 *
 *     webhooks:
 *       account.opened:
 *         post:
 *           operationId: notifyAccountOpened
 *           requestBody:
 *             content:
 *               application/json:
 *                 schema: { $ref: '#/components/schemas/AccountOpenedNotification' }
 *
 * An event type the contract does not describe is a defect: what subscribers receive
 * is exactly what the contract documents.
 *
 * Requires `acta/http`.
 */
final readonly class ContractPayloadValidator implements PayloadValidator
{
    private SchemaValidator $schemas;

    /** @var array<string, Operation> webhooks keyed by name */
    private array $webhooks;

    public function __construct(Contract $contract, ?SchemaValidator $schemas = null)
    {
        $this->schemas = $schemas ?? new SchemaValidator($contract);
        $webhooks = [];

        foreach ($contract->webhooks as $operation) {
            // A webhook operation carries the name of its webhook where a path operation carries its path.
            $webhooks[$operation->path] ??= $operation;
        }

        $this->webhooks = $webhooks;
    }

    #[Override]
    public function validate(string $eventType, array $body): array
    {
        $operation = $this->webhooks[$eventType] ?? null;

        if ($operation === null) {
            return [sprintf('The contract describes no "%s" webhook.', $eventType)];
        }

        $media = MediaType::match($operation->requestBody->content ?? [], 'application/json');

        if ($media?->schemaPointer === null) {
            return [];
        }

        $defects = [];

        foreach ($this->schemas->validate(SchemaValidator::toJson($body, true), $media->schemaPointer) as $pointer => $messages) {
            foreach ($messages as $message) {
                $defects[] = sprintf('%s: %s', $pointer === '' ? '/' : $pointer, $message);
            }
        }

        return $defects;
    }
}
