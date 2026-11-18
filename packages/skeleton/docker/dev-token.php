<?php

declare(strict_types=1);

/*
 * Prints an access token issued by the Keycloak of the local stack to its development
 * client, "<project>-dev", with the client credentials grant:
 *
 *     make token
 *     curl -H "Authorization: Bearer $(make -s token)" https://api.<domain>/accounts
 *
 * Give scopes as arguments to ask for less than the client is allowed:
 *
 *     make token ARGS=banking:read
 */

$issuer = getenv('OIDC_ISSUER') ?: '';
$client = (getenv('COMPOSE_PROJECT_NAME') ?: '') . '-dev';
$scopes = array_slice($argv, 1);

$response = @file_get_contents($issuer . '/protocol/openid-connect/token', false, stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: application/x-www-form-urlencoded',
    'content' => http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $client,
        'client_secret' => $client . '-secret',
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
