<?php

declare(strict_types=1);

use App\Platform\Env;

/*
 * Settings of the application, read from the environment. See .env.dist for the list.
 */
return [
    // Adds exception details to problem documents and watches the contract for changes.
    'debug' => Env::bool('APP_DEBUG'),

    'acta' => [
        // What the console and the logs call the application.
        'name' => Env::string('APP_NAME', 'Application'),
        'version' => Env::string('APP_VERSION', 'dev'),
        'root' => dirname(__DIR__, 2),
        'cache_dir' => 'data/cache',
        // A Valkey or Redis server (redis://host:6379) for what the instances of the
        // application share: the metadata and the keys of the identity provider. Empty:
        // files under cache_dir.
        'cache_dsn' => Env::string('CACHE_DSN'),

        // "postgres", or "memory" to keep all state in the process (tests).
        'persistence' => Env::string('APP_PERSISTENCE', 'postgres'),

        'database' => [
            'dsn' => Env::string('DATABASE_DSN', 'pgsql:host=127.0.0.1;port=5432;dbname=app'),
            'user' => Env::string('DATABASE_USER', 'app'),
            'password' => Env::string('DATABASE_PASSWORD'),
        ],

        // Projections brought up to date right after each command, for a machine where
        // no projection worker runs. Leave empty in production.
        'projections_after_command' => Env::list('PROJECTIONS_AFTER_COMMAND'),

        'http' => [
            'contract' => 'openapi/openapi.yaml',
            // The compiled contract; an empty value parses the YAML on every request.
            'contract_cache' => 'data/cache/contract.php',
            'base_path' => Env::string('HTTP_BASE_PATH'),
            // "fail" turns a response that breaks the contract into a 500, "log" lets it through.
            'response_validation' => Env::string('HTTP_RESPONSE_VALIDATION', 'log'),
            'idempotency_ttl' => 86400,
        ],

        'oidc' => [
            'issuer' => Env::string('OIDC_ISSUER'),
            // The identifier of this API, expected in the "aud" claim of access tokens.
            'audience' => Env::string('OIDC_AUDIENCE'),
            // Accepts an http:// issuer. For a local identity provider only.
            'allow_insecure' => Env::bool('OIDC_ALLOW_INSECURE'),
            // The public client `bin/console auth:login` logs in with.
            'client_id' => Env::string('OIDC_CLIENT_ID'),
            'scopes' => ['openid', 'offline_access'],
            'token_file' => 'data/token.json',
        ],

        'webhooks' => [
            // Who receives the outgoing webhooks:
            // ['id' => 'crm', 'url' => 'https://…', 'secret' => 'whsec_…', 'events' => ['account.*']]
            'subscriptions' => [],
        ],

        'log' => [
            'stream' => Env::string('LOG_STREAM', 'php://stderr'),
            'level' => Env::string('LOG_LEVEL', 'info'),
        ],
    ],
];
