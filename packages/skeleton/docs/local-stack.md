# The local stack

`make up` runs the application the way a deployment is reached: over HTTPS, with
[Keycloak](https://www.keycloak.org) 26 as the OAuth 2.0 / OpenID Connect provider.

| Address | What answers |
| --- | --- |
| <https://api.mybank.io> | The API (`/health`, `/openapi.json`, `/accounts`…) |
| <https://auth.mybank.io> | Keycloak: the realm `mybank`, and the admin console (`admin` / `admin`) |

The sources are mounted in the containers: a change is live at once.

## Three names

Everything above follows from the first lines of `.env`:

| Variable | Here | What it names |
| --- | --- | --- |
| `COMPOSE_PROJECT_NAME` | `mybank` | The containers and volumes; the Keycloak realm; the prefix of its clients (`mybank-api`, `mybank-console`, `mybank-dev`) |
| `APP_NAME` | `MyBank` | The application, for people: the console, the login page |
| `APP_DOMAIN` | `mybank.io` | The two addresses, `api.` and `auth.` under it, and the certificate |

`composer create-project` asks for them once. To change them afterwards, edit `.env`
and `.env.dist`, then:

```sh
make down ARGS=--volumes    # the realm was imported under the old names
make hosts && make up       # the certificate is made again for the new domain
```

## How it fits together

```
browser, curl ──https──▶ web (nginx, TLS) ──┬─ api.mybank.io ──▶ api (PHP-FPM)
                                            └─ auth.mybank.io ─▶ keycloak
api ──https──▶ auth.mybank.io (the same nginx, by its network alias) ──▶ keycloak
api, workers ──▶ database (PostgreSQL), cache (Valkey)
```

- **TLS.** `make certs` asks mkcert for one certificate valid for both names
  (`docker/certs/local.pem`, never committed) and copies the root certificate of mkcert
  next to it. `mkcert -install` makes browsers and curl on this machine trust it. nginx
  is the only service that speaks TLS; port 80 redirects to 443.
- **One address for the issuer.** Keycloak is told its public address
  (`KC_HOSTNAME`), so every token says `"iss": "https://auth.mybank.io/realms/mybank"`
  wherever it was asked from. In the Compose network that name resolves to nginx, and
  the PHP containers trust the mkcert root (`docker/php/entrypoint.sh`): the API
  verifies tokens over HTTPS, with `OIDC_ALLOW_INSECURE=0`, exactly as it would against
  a hosted provider.
- **Cache.** The metadata and the signing keys of Keycloak are kept in Valkey
  (`CACHE_DSN`), so the API and every worker share one copy instead of fetching their
  own. Nothing in it is worth keeping: it is not persisted.
- **Data.** PostgreSQL holds the `app` database, `app_test` for the tests and `keycloak`.
  It is published on port 54320 and Valkey on 63790 (`DATABASE_PORT`, `CACHE_PORT`).

## The realm

`docker/keycloak/realm.json` is imported when Keycloak first starts with an empty
database; its `${APP_REALM}`, `${APP_NAME}` and `${APP_DOMAIN}` are replaced by the
names of `.env`. What is changed afterwards in the admin console lives in the database;
to apply an edited file, start over with `make down ARGS=--volumes`.

| | |
| --- | --- |
| Scopes `banking:read`, `banking:write` | What the operations of `openapi/openapi.yaml` require |
| Scope `mybank-api` | Adds `"aud": "mybank-api"` to access tokens: the `OIDC_AUDIENCE` of the API |
| Client `mybank-dev` (secret `mybank-dev-secret`) | Client credentials, for `make token`. The banking scopes are optional: `make token ARGS=banking:read` yields a token that may read and not write |
| Client `mybank-console` | Public, device flow: `make login`, then approve in the browser |
| User `ada` (password `ada`) | Someone to log in as |

The secrets and passwords of this stack are for a laptop. None of it is a production
setup: Keycloak runs in development mode (`start-dev`).

## Inside the Acta monorepo

There, the four libraries are installed as symbolic links to `../`, which a container
that only sees this directory cannot follow. `compose.monorepo.yaml` mounts them where
the links point; the Makefile adds it by itself as long as the file exists. A project
made with `composer create-project` does not have it.

## Troubleshooting

- **The browser does not trust the certificate**: run `mkcert -install`, then restart
  the browser. Firefox needs `nss` (`brew install nss`) before `mkcert -install`.
- **`make up` fails on a port**: 80, 443, 54320 or 63790 is taken on this machine. The
  last two are set in `.env`.
- **The API answers 401 to a token that looks right**: compare its `iss` with
  `OIDC_ISSUER` and its `aud` with `OIDC_AUDIENCE`; `make logs ARGS=api` says which
  check failed.
