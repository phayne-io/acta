# MyBank on this machine: https://api.mybank.io and https://auth.mybank.io. `make` lists
# the commands; README.md says what is behind them.

COMPOSE := docker compose
RUN := $(COMPOSE) run --rm --no-deps api
HOSTS := api.mybank.io auth.mybank.io
SKELETON := ../packages/skeleton

.DEFAULT_GOAL := help
.PHONY: help certs hosts up down ps logs install migrate console shell login token test check

help: ## Lists the commands
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

certs/mybank.pem:
	@command -v mkcert >/dev/null || { echo "mkcert is missing: brew install mkcert (https://github.com/FiloSottile/mkcert)"; exit 1; }
	mkcert -install
	mkcert -cert-file certs/mybank.pem -key-file certs/mybank-key.pem $(HOSTS)
	cp "$$(mkcert -CAROOT)/rootCA.pem" certs/rootCA.pem

certs: certs/mybank.pem ## Makes the certificate of the two names with mkcert, once

hosts: ## Points the two names at this machine in /etc/hosts (asks for sudo)
	@grep -q 'api\.mybank\.io' /etc/hosts \
		&& echo "/etc/hosts already knows api.mybank.io" \
		|| { echo "127.0.0.1 $(HOSTS)" | sudo tee -a /etc/hosts >/dev/null && echo "Added: 127.0.0.1 $(HOSTS)"; }

$(SKELETON)/vendor: $(SKELETON)/composer.json
	$(RUN) composer install

up: certs $(SKELETON)/vendor ## Starts the API, its workers, PostgreSQL and Keycloak, and waits until they answer
	$(COMPOSE) up --build --detach --wait
	@grep -q 'api\.mybank\.io' /etc/hosts || echo "api.mybank.io is not in /etc/hosts yet: run \`make hosts\`"
	@echo "API:      https://api.mybank.io/health — contract: https://api.mybank.io/openapi.json"
	@echo "Keycloak: https://auth.mybank.io (admin / admin), realm \"mybank\""

down: ## Stops everything; `make down ARGS=--volumes` also forgets the data, Keycloak's included
	$(COMPOSE) down $(ARGS)

ps: ## Shows the state of the services
	$(COMPOSE) ps --all

logs: ## Follows the logs; `make logs ARGS=keycloak` for one service
	$(COMPOSE) logs --follow $(ARGS)

install: ## Installs the dependencies of the application
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
	@$(COMPOSE) exec -T api php /docker/php/token.php $(or $(ARGS),banking:read banking:write)

test: ## Runs the tests of the application, those against PostgreSQL included
	$(COMPOSE) run --rm -e TEST_DATABASE_DSN="pgsql:host=database;port=5432;dbname=app_test" api composer test

check: ## Runs everything the continuous integration runs for the application
	$(COMPOSE) run --rm -e TEST_DATABASE_DSN="pgsql:host=database;port=5432;dbname=app_test" api composer check
