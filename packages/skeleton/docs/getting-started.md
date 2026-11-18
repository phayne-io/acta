# Getting started

## With Docker

You need Docker and [mkcert](https://github.com/FiloSottile/mkcert).

```sh
make hosts     # once: api.mybank.io and auth.mybank.io in /etc/hosts (asks for sudo)
make up        # makes the certificate on the first run, builds, starts, waits
```

starts, from one image run several ways:

| Service | Role |
| --- | --- |
| `web` | nginx, which terminates TLS: the API on <https://api.mybank.io>, Keycloak on <https://auth.mybank.io> |
| `api` | PHP-FPM |
| `migrate` | creates the tables, then exits |
| `projector-accounts`, `projector-webhooks` | keep the read model and the webhook outbox up to date |
| `webhook-sender` | sends the queued webhooks to their subscribers |
| `database` | PostgreSQL, on port 54320 |
| `cache` | Valkey, on port 63790: the keys of the identity provider, shared by all of the above |
| `keycloak` | the identity provider, with the realm `mybank` |

`make` lists the other commands: `make logs`, `make console ARGS="projection:status"`,
`make test`, `make check`, `make down`.

The settings are in `.env`, created from `.env.dist` on the first `make up`. Its first
three lines name the project; [The local stack](local-stack.md) says what follows from
them and how the pieces fit together.

### A token

The API accepts Bearer JWT issued by the identity provider of `OIDC_ISSUER`, here the
realm `mybank` of Keycloak:

```sh
make token                      # a machine: the client credentials of "mybank-dev"
make token ARGS=banking:read    # the same, allowed to read only
make login                      # a person, from the console: user ada, password ada
```

Tokens carry the audience `mybank-api` and the scopes `banking:read banking:write`;
clients, scopes and users are in `docker/keycloak/realm.json`.

To use another provider (Auth0, Microsoft Entra ID, …), point `OIDC_ISSUER` at it and
set `OIDC_AUDIENCE` to the identifier it gives this API.

## Without Docker

You need PHP 8.5 with `pdo_pgsql` and `bcmath`, and a PostgreSQL database.

```sh
composer install
cp config/autoload/local.php.dist config/autoload/local.php

export DATABASE_DSN='pgsql:host=127.0.0.1;port=5432;dbname=app' DATABASE_USER=app DATABASE_PASSWORD=app
export OIDC_ISSUER=https://your-idp.example OIDC_AUDIENCE=mybank-api

bin/console es:migrate
composer serve          # http://localhost:8080
```

`config/autoload/local.php` turns on what suits a single machine: problem documents
with exception details, responses checked against the contract, and read models brought
up to date right after each command, so that no projection worker is needed.

## The first requests

```sh
TOKEN=$(make -s token)
API=https://api.mybank.io

# Open an account. The Idempotency-Key makes the request safe to retry.
curl -i -X POST $API/accounts \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -H 'Idempotency-Key: 6f1c0d5e' \
     -d '{"owner": "Ada", "currency": "EUR"}'

# Read it. The ETag is its version.
curl -i -H "Authorization: Bearer $TOKEN" $API/accounts/<id>

# Deposit, only if the account is still as it was read.
curl -i -X POST $API/accounts/<id>/deposits \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'If-Match: "1"' \
     -d '{"amount": "12000", "currency": "EUR"}'

# What the bank refuses comes back as an RFC 9457 problem document.
curl -X POST $API/accounts/<id>/withdrawals \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"amount": "999999", "currency": "EUR"}'
# {"type":"urn:problem:banking:insufficient-funds","title":"Business rule violated","status":422,…}
```

The same use cases from the console:

```sh
bin/console banking:account:open Grace --currency USD
bin/console banking:account:show <id>
bin/console list
```

## Where to go next

Read [Architecture](architecture.md) with `src/Banking` open, then scaffold a context of
your own with [Adding a context](adding-a-context.md).
