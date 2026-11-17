<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Http;

use Acta\EventSourcing\Bus\QueryBus;
use Acta\Http\EventSourcing\IfMatch;
use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\Exception\HttpException;
use Acta\Http\Handler\Operation;
use Acta\Http\Response\Responder;
use Acta\Http\Validation\Input;
use App\Banking\Application\Query\AccountView;
use App\Banking\Application\Query\GetAccount;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Primary adapter: `GET /accounts/{accountId}`.
 *
 * The `ETag` of the response is the version of the account; clients send it back in
 * `If-Match` to act on what they have read.
 */
#[Operation('getAccount')]
final readonly class GetAccountAction implements RequestHandlerInterface
{
    public function __construct(
        private QueryBus $queries,
        private MessageFactory $messages,
        private Responder $responder,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The path parameters become the properties of the query.
        $account = $this->queries->ask($this->messages->create(GetAccount::class, Input::from($request)));

        return $account instanceof AccountView
            ? $this->responder->json(AccountResource::from($account), headers: ['ETag' => IfMatch::etag($account->version)])
            : throw HttpException::notFound('There is no such account.');
    }
}
