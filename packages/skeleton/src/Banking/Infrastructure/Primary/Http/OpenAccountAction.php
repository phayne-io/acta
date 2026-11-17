<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Http;

use Acta\EventSourcing\Bus\CommandBus;
use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\EventSourcing\MetadataFactory;
use Acta\Http\Handler\Operation;
use Acta\Http\Response\Responder;
use Acta\Http\Validation\Input;
use App\Banking\Application\Command\OpenAccount;
use App\Banking\Domain\AccountId;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Primary adapter: `POST /accounts`.
 *
 * The request reaches this handler authenticated and validated against the contract.
 * What is left is to turn it into a command; the identifier is chosen here, so the
 * response can say where the account will be without waiting for the read model.
 */
#[Operation('openAccount')]
final readonly class OpenAccountAction implements RequestHandlerInterface
{
    public function __construct(
        private CommandBus $commands,
        private MessageFactory $messages,
        private MetadataFactory $metadata,
        private Responder $responder,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = AccountId::generate();

        $input = Input::from($request);

        // The fields of the validated body become the properties of the command; what
        // the domain models with a type of its own is translated here, in the adapter.
        $command = $this->messages->create(OpenAccount::class, $input, [
            'accountId' => $id,
            'currency' => MoneyFormat::currency(is_array($input->body) ? $input->body['currency'] ?? null : null, 'EUR'),
        ]);

        $this->commands->dispatch($command, $this->metadata->fromRequest($request));

        return $this->responder->created('/accounts/' . $id, ['id' => $id->value]);
    }
}
