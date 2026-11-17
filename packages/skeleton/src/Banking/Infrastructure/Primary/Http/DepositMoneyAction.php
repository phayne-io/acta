<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Http;

use Acta\EventSourcing\Bus\CommandBus;
use Acta\Http\EventSourcing\IfMatch;
use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\EventSourcing\MetadataFactory;
use Acta\Http\Handler\Operation;
use Acta\Http\Response\Responder;
use Acta\Http\Validation\Input;
use App\Banking\Application\Command\DepositMoney;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Primary adapter: `POST /accounts/{accountId}/deposits`.
 *
 * A client that sends `If-Match` with the `ETag` it read makes the deposit conditional
 * on the account not having changed since.
 */
#[Operation('depositMoney')]
final readonly class DepositMoneyAction implements RequestHandlerInterface
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
        $input = Input::from($request);

        // The account comes from the path and the reference from the body, by name. What
        // the domain models with a type of its own is translated here, in the adapter.
        $command = $this->messages->create(DepositMoney::class, $input, [
            'money' => MoneyFormat::decode($input->body),
            'expectedVersion' => IfMatch::expectedVersion($request),
        ]);

        $this->commands->dispatch($command, $this->metadata->fromRequest($request));

        return $this->responder->empty();
    }
}
