<?php

declare(strict_types=1);

/*
 * Prints an access token issued by the development identity provider:
 *
 *     make token
 *     curl -H "Authorization: Bearer $(make -s token)" http://localhost:8080/accounts
 *
 * It must run inside the Compose network: the provider signs tokens with the address it
 * was called at as their issuer, and the API expects the address it knows, OIDC_ISSUER.
 */

$issuer = getenv('OIDC_ISSUER') ?: 'http://idp:8080/default';

$response = @file_get_contents($issuer . '/token', false, stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: application/x-www-form-urlencoded',
    'content' => http_build_query(['grant_type' => 'client_credentials', 'client_id' => 'developer', 'client_secret' => 'developer']),
    'timeout' => 5,
]]));

$token = is_string($response) ? json_decode($response, true)['access_token'] ?? null : null;

if (!is_string($token)) {
    fwrite(STDERR, sprintf("No token could be obtained from %s. Is the \"idp\" service up?\n", $issuer));

    exit(1);
}

echo $token, "\n";
