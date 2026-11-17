# MyBank

MyBank is the application in [`packages/skeleton`](../packages/skeleton). This directory
runs it locally the way a real deployment would be reached: over HTTPS, with [Keycloak](https://www.keycloak.org) 26 as
the OAuth 2.0 / OpenID Connect provider.

| Address | What answers |
| --- | --- |
| <https://api.mybank.io> | The API (`/health`, `/openapi.json`, `/accounts`…) |
| <https://auth.mybank.io> | Keycloak: the realm `mybank`, and the admin console (`admin` / `admin`) |

It runs the code of this working copy: a change in `packages/` is live at once.

## Start

You need Docker and [mkcert](https://github.com/FiloSottile/mkcert) (`brew install mkcert`).

```sh
cd docker
make hosts     # once: 127.0.0.1 api.mybank.io auth.mybank.io in /etc/hosts (sudo)
make up        # makes the certificate on the first run, builds, starts, waits
```

```sh
TOKEN=$(make -s token)

curl -i -X POST https://api.mybank.io/accounts \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"owner": "Ada", "currency": "EUR"}'
# 201 Created — Location: /accounts/0197…
```

`make` lists the other commands; `make down ARGS=--volumes` starts over from nothing.

## How it fits together

```
browser, curl ──https──▶ web (nginx, TLS) ──┬─ api.mybank.io ──▶ api (PHP-FPM)
                                            └─ auth.mybank.io ─▶ keycloak
api ──https──▶ auth.mybank.io (the same nginx, by its network alias) ──▶ keycloak
```

- **TLS.** `make certs` asks mkcert for one certificate valid for both names
  (`certs/mybank.pem`, never committed) and copies the root certificate of mkcert next
  to it. `mkcert -install` makes browsers and curl on this machine trust it. nginx is
  the only service that speaks TLS; port 80 redirects to 443.
- **One address for the issuer.** Keycloak is told its public address
  (`KC_HOSTNAME=https://auth.mybank.io`), so every token says
  `"iss": "https://auth.mybank.io/realms/mybank"` wherever it was asked from. In the
  Compose network that name resolves to nginx, and the PHP containers trust the mkcert
  root (`php/entrypoint.sh`): the API verifies tokens over HTTPS, with
  `OIDC_ALLOW_INSECURE=0`, exactly as it would against a hosted provider.
- **Data.** PostgreSQL holds the `app` database, `app_test` for the tests and `keycloak`.
  It is published on port 54320 (`DATABASE_PORT=5432 make up` to change it).

## The realm

`keycloak/mybank-realm.json` is imported when Keycloak first starts with an empty
database. What is changed afterwards in the admin console lives in the database; to
apply an edited file, start over with `make down ARGS=--volumes`.

| | |
| --- | --- |
| Scopes `banking:read`, `banking:write` | What the operations of `openapi/openapi.yaml` require |
| Scope `mybank-api` | Adds `"aud": "mybank-api"` to access tokens: the `OIDC_AUDIENCE` of the API |
| Client `mybank-dev` (secret `mybank-dev-secret`) | Client credentials, for `make token`. The banking scopes are optional: `make token ARGS=banking:read` yields a token that may read and not write |
| Client `mybank-console` | Public, device flow: `make login`, then approve in the browser |
| User `ada` (password `ada`) | Someone to log in as |

The secrets and passwords in this directory are for a laptop. None of this is a
production setup: Keycloak runs in development mode (`start-dev`).

## Troubleshooting

- **The browser does not trust the certificate**: run `mkcert -install`, then restart
  the browser. Firefox needs `nss` (`brew install nss`) before `mkcert -install`.
- **`make up` fails on a port**: 80, 443 or 54320 is taken on this machine.
- **The API answers 401 to a token that looks right**: compare its `iss` with
  `OIDC_ISSUER` and its `aud` with `OIDC_AUDIENCE`; `make logs ARGS=api` says which
  check failed.
