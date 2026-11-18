# Everyday commands, run in the containers of compose.yaml. `make` lists them;
# docs/local-stack.md says what is behind them.

# Inside the Acta monorepo the libraries are symbolic links to ../: see the file.
ifneq ($(wildcard compose.monorepo.yaml),)
export COMPOSE_FILE := compose.yaml:compose.monorepo.yaml
endif

COMPOSE := docker compose
RUN := $(COMPOSE) run --rm --no-deps api
TEST_DATABASE := -e TEST_DATABASE_DSN="pgsql:host=database;port=5432;dbname=app_test"

# Read when a command runs, not before: the first `make up` creates .env.
APP_DOMAIN = $(shell sed -n 's/^APP_DOMAIN=//p' .env)
HOSTS = api.$(APP_DOMAIN) auth.$(APP_DOMAIN)
CERTS := docker/certs

.DEFAULT_GOAL := help
.PHONY: help certs hosts up down ps logs install migrate console shell login token test check cs-fix rebuild

help: ## Lists the commands
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

.env:
	cp .env.dist .env

# Made again when APP_DOMAIN changes, since .env is then newer than the certificate.
$(CERTS)/local.pem: .env
	@command -v mkcert >/dev/null || { echo "mkcert is missing: brew install mkcert (https://github.com/FiloSottile/mkcert)"; exit 1; }
	mkcert -install
	mkcert -cert-file $(CERTS)/local.pem -key-file $(CERTS)/local-key.pem $(HOSTS)
	cp "$$(mkcert -CAROOT)/rootCA.pem" $(CERTS)/rootCA.pem

certs: $(CERTS)/local.pem ## Makes the certificate of the two names with mkcert

hosts: .env ## Points the two names at this machine in /etc/hosts (asks for sudo)
	@grep -q "api\.$(APP_DOMAIN)" /etc/hosts \
		&& echo "/etc/hosts already knows api.$(APP_DOMAIN)" \
		|| { echo "127.0.0.1 $(HOSTS)" | sudo tee -a /etc/hosts >/dev/null && echo "Added: 127.0.0.1 $(HOSTS)"; }

vendor: composer.json | .env certs
	$(RUN) composer install

up: .env certs vendor ## Starts the API, its workers, PostgreSQL, Valkey and Keycloak, and waits until they answer
	$(COMPOSE) up --build --detach --wait
	@grep -q "api\.$(APP_DOMAIN)" /etc/hosts || echo "api.$(APP_DOMAIN) is not in /etc/hosts yet: run \`make hosts\`"
	@echo "API:      https://api.$(APP_DOMAIN)/health — contract: https://api.$(APP_DOMAIN)/openapi.json"
	@echo "Keycloak: https://auth.$(APP_DOMAIN) (admin / admin)"

down: ## Stops everything; `make down ARGS=--volumes` also forgets the data, Keycloak's included
	$(COMPOSE) down $(ARGS)

ps: ## Shows the state of the services
	$(COMPOSE) ps --all

logs: ## Follows the logs; `make logs ARGS=api` for one service
	$(COMPOSE) logs --follow $(ARGS)

install: .env certs ## Installs the dependencies
	$(RUN) composer install

migrate: ## Creates the tables that do not exist yet
	$(COMPOSE) run --rm migrate

console: ## Runs a console command: `make console ARGS="projection:status"`
	$(COMPOSE) run --rm api bin/console $(ARGS)

shell: ## Opens a shell in the application container
	$(COMPOSE) run --rm api sh

login: ## Logs the console in as a person, with the device flow (user ada, password ada)
	$(COMPOSE) run --rm api bin/console auth:login

token: ## Prints an access token for the API; `make token ARGS=banking:read` for fewer scopes
	@$(COMPOSE) exec -T api php docker/dev-token.php $(or $(ARGS),banking:read banking:write)

test: ## Runs the tests, those against PostgreSQL included
	$(COMPOSE) run --rm $(TEST_DATABASE) api composer test

check: ## Runs everything the continuous integration runs
	$(COMPOSE) run --rm $(TEST_DATABASE) api composer check

cs-fix: ## Applies the coding standard
	$(RUN) composer cs:fix

rebuild: ## Empties a read model and replays the events into it: `make rebuild ARGS=banking_accounts`
	$(COMPOSE) run --rm api bin/console projection:rebuild $(ARGS) --force
