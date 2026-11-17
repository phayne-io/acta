# Getting started

## With Docker

```sh
make up
```

starts, from one image run several ways:

| Service | Role |
| --- | --- |
| `web`, `api` | nginx in front of PHP-FPM: the API on <http://localhost:8080> |
| `migrate` | creates the tables, then exits |
| `projector-accounts`, `projector-webhooks` | keep the read model and the webhook outbox up to date |
| `webhook-sender` | sends the queued webhooks to their subscribers |
| `database` | PostgreSQL, on port 5432 |
| `idp` | a development identity provider, on port 8081 |

`make` lists the other commands: `make logs`, `make console ARGS="projection:status"`,
`make test`, `make check`, `make down`.

The settings are in `.env`, created from `.env.dist` on the first `make up`.

### A token

The API accepts Bearer JWT issued by the identity provider of `OIDC_ISSUER`. In
development, that is a stand-in that signs a token for whoever asks:

```sh
make token
```

Every token it issues carries the audience `acta-api` and the scopes `banking:read
banking:write`; change them in `compose.yaml`. The token must be requested from inside
the Compose network, which `make token` does: the stand-in signs with the address it
was called at, and the API expects the address it knows.

For anything beyond trying the API, point `OIDC_ISSUER` at your real provider
(Keycloak, Auth0, Microsoft Entra ID, …) and set `OIDC_AUDIENCE` to the identifier it
gives this API.

## Without Docker

You need PHP 8.5 with `pdo_pgsql` and `bcmath`, and a PostgreSQL database.

```sh
composer install
cp config/autoload/local.php.dist config/autoload/local.php

export DATABASE_DSN='pgsql:host=127.0.0.1;port=5432;dbname=app' DATABASE_USER=app DATABASE_PASSWORD=app
export OIDC_ISSUER=https://your-idp.example OIDC_AUDIENCE=acta-api

bin/console es:migrate
composer serve          # http://localhost:8080
```

`config/autoload/local.php` turns on what suits a single machine: problem documents
with exception details, responses checked against the contract, and read models brought
up to date right after each command, so that no projection worker is needed.

## The first requests

```sh
TOKEN=$(make -s token)
API=http://localhost:8080

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
