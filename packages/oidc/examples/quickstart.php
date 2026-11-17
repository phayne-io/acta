<?php

declare(strict_types=1);

/*
 * Both sides of OAuth 2.0 / OpenID Connect in one file, without network.
 *
 *     php examples/quickstart.php
 *
 * An in-memory identity provider (Acta\Oidc\Testing\FakeIdentityProvider) stands in
 * for Keycloak, Entra ID or Auth0. Against a real one, only the issuer and the PSR-18
 * client change.
 */

namespace Acta\Example\Oidc;

use Acta\Oidc\Client\AuthenticatedHttpClient;
use Acta\Oidc\Client\ClientCredentialsTokenProvider;
use Acta\Oidc\Client\ClientSecretBasic;
use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\PublicClient;
use Acta\Oidc\Discovery\Discovery;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Jwks\JwksProvider;
use Acta\Oidc\Server\BearerAuthMiddleware;
use Acta\Oidc\Testing\FakeIdentityProvider;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\JwtVerifier;
use Acta\Oidc\Token\Principal;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require __DIR__ . '/../vendor/autoload.php';

$responses = new ResponseFactory();
$streams = new StreamFactory();

// --- The identity provider -------------------------------------------------------------

$idp = new FakeIdentityProvider($responses, $streams, 'https://idp.example.test');
$idp->registerClient('billing-service', 'service-secret');   // a confidential client
$idp->registerClient('bank-cli');                             // a public client
$idp->setUser('ada', ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

// --- Shared by both sides: how to reach the provider, what it is, which keys it uses ---

$transport = new Transport($idp, new RequestFactory(), $streams);   // $idp is the PSR-18 client here
$provider = new Discovery('https://idp.example.test', $transport);
$keys = new JwksProvider($provider, $transport);

// --- Resource server: an API protected by Bearer JWT -----------------------------------

$verifier = new AccessTokenVerifier(new JwtVerifier($provider, $keys), audience: 'api');
$protection = new BearerAuthMiddleware($verifier, $responses, $streams, scopes: ['accounts:read']);

$api = new class ($protection, $responses, $streams) implements ClientInterface, RequestHandlerInterface {
    public function __construct(
        private readonly BearerAuthMiddleware $protection,
        private readonly ResponseFactory $responses,
        private readonly StreamFactory $streams,
    ) {}

    /** The API is called in process: this PSR-18 client runs the request through its middleware. */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $serverRequest = new ServerRequest([], [], $request->getUri(), $request->getMethod(), $request->getBody(), $request->getHeaders());

        return $this->protection->process($serverRequest, $this);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Principal::from($request);
        $body = json_encode(['subject' => $principal->subject, 'client' => $principal->clientId, 'scopes' => $principal->scopes]);

        return $this->responses->createResponse(200)->withBody($this->streams->createStream((string) $body));
    }
};

$call = static function (string $title, ClientInterface $client, array $headers = []) use ($api): void {
    $request = new RequestFactory()->createRequest('GET', 'https://api.example.test/accounts');

    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }

    $response = $client->sendRequest($request);
    printf("%-34s %d  %s\n", $title, $response->getStatusCode(), $response->getHeaderLine('WWW-Authenticate') ?: $response->getBody());
};

echo "Resource server\n";
$call('without token', $api);
$call('with a token of another issuer', $api, ['Authorization' => 'Bearer ' . $idp->issueAccessToken(['iss' => 'https://evil.example.test'])]);
$call('with a token lacking the scope', $api, ['Authorization' => 'Bearer ' . $idp->issueAccessToken(['scope' => 'profile'])]);
$call('with a valid token', $api, ['Authorization' => 'Bearer ' . $idp->issueAccessToken(['sub' => 'ada', 'scope' => 'accounts:read'])]);

// --- Client credentials: a service calling the API as itself ---------------------------

$service = new OAuthClient('billing-service', new ClientSecretBasic('service-secret'), $provider, $transport);
$authenticated = new AuthenticatedHttpClient($api, new ClientCredentialsTokenProvider($service, ['accounts:read']));

echo "\nClient credentials\n";
$call('service to service', $authenticated);
$call('again, with the cached token', $authenticated);
printf("%-34s %d\n", 'token requests made', $idp->requestCount('/token'));

// --- Authorization code with PKCE: a user logging in -----------------------------------

$web = new OAuthClient('billing-service', new ClientSecretBasic('service-secret'), $provider, $transport);
$login = $web->authorizationRequest('https://app.example.test/callback', ['openid', 'profile', 'accounts:read']);

// Here the application stores $login in the session and redirects the user to $login->url.
// The user logs in at the provider, which redirects back with these query parameters:
$callback = $idp->authorize($login->url, subject: 'ada');
$tokens = $web->exchangeCode($login, $callback);

echo "\nAuthorization code + PKCE\n";
printf("%-34s %s\n", 'logged in as', $tokens->idTokenClaims?->string('name'));
printf("%-34s %s\n", 'UserInfo e-mail', $web->userInfo($tokens)->string('email'));
$call('calling the API as the user', $api, ['Authorization' => 'Bearer ' . $tokens->accessToken]);
printf("%-34s %s\n", 'refreshed', $web->refresh($tokens)->accessToken !== $tokens->accessToken ? 'yes' : 'no');

// --- Device authorization: a command-line tool, without browser ------------------------

$cli = new OAuthClient('bank-cli', new PublicClient(), $provider, $transport);
$device = $cli->requestDeviceAuthorization(['openid', 'accounts:read']);

echo "\nDevice authorization\n";
printf("%-34s open %s and enter %s\n", 'the tool tells the user', $device->verificationUri, $device->userCode);

$polls = 0;
$tokens = $cli->pollDeviceToken($device, static function () use ($idp, $device, &$polls): void {
    // Instead of sleeping, the user approves on their phone before the third poll.
    if (++$polls === 3) {
        $idp->approveDevice($device->userCode, subject: 'ada');
    }
});
printf("%-34s %s, after %d polls\n", 'logged in as', $tokens->idTokenClaims?->string('sub'), $polls);
