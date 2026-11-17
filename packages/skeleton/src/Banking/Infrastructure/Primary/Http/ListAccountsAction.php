<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Http;

use Acta\EventSourcing\Bus\QueryBus;
use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\Handler\Operation;
use Acta\Http\Response\Responder;
use Acta\Http\Validation\Input;
use App\Banking\Application\Query\AccountView;
use App\Banking\Application\Query\ListAccounts;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Primary adapter: `GET /accounts`.
 */
#[Operation('listAccounts')]
final readonly class ListAccountsAction implements RequestHandlerInterface
{
    public function __construct(
        private QueryBus $queries,
        private MessageFactory $messages,
        private Responder $responder,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The validated query parameters become the properties of the query.
        $accounts = $this->queries->ask($this->messages->create(ListAccounts::class, Input::from($request)));
        $items = [];

        foreach (is_iterable($accounts) ? $accounts : [] as $account) {
            if ($account instanceof AccountView) {
                $items[] = AccountResource::from($account);
            }
        }

        return $this->responder->json(['items' => $items]);
    }
}
