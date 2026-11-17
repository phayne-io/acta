<?php

declare(strict_types=1);

/*
 * Prints an access token issued by Keycloak to the "mybank-dev" client, with the client
 * credentials grant:
 *
 *     make token
 *     curl -H "Authorization: Bearer $(make -s token)" https://api.mybank.io/accounts
 *
 * Give scopes as arguments to ask for less than the client is allowed:
 *
 *     php token.php banking:read
 */

$issuer = getenv('OIDC_ISSUER') ?: 'https://auth.mybank.io/realms/mybank';
$scopes = array_slice($argv, 1);

$response = @file_get_contents($issuer . '/protocol/openid-connect/token', false, stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: application/x-www-form-urlencoded',
    'content' => http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => 'mybank-dev',
        'client_secret' => 'mybank-dev-secret',
        ...($scopes === [] ? [] : ['scope' => implode(' ', $scopes)]),
    ]),
    'timeout' => 5,
    'ignore_errors' => true,
]]));

$body = is_string($response) ? json_decode($response, true) : null;
$token = is_array($body) ? $body['access_token'] ?? null : null;

if (!is_string($token)) {
    fwrite(STDERR, sprintf(
        "No token could be obtained from %s%s\n",
        $issuer,
        is_string($response) && $response !== '' ? ': ' . $response : '. Is the "keycloak" service healthy?',
    ));

    exit(1);
}

echo $token, "\n";
