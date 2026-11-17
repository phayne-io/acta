# Everyday commands, run in the containers of compose.yaml. `make` lists them.

COMPOSE := docker compose
RUN := $(COMPOSE) run --rm --no-deps api

.DEFAULT_GOAL := help
.PHONY: help up down logs install migrate console shell token test check cs-fix rebuild

help: ## Lists the commands
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

.env:
	cp .env.dist .env

vendor: composer.json
	$(RUN) composer install

up: .env vendor ## Starts the API on http://localhost:8080, its workers, PostgreSQL and the identity provider
	$(COMPOSE) up --build --detach
	@echo "API: http://localhost:8080/health — contract: http://localhost:8080/openapi.json"

down: ## Stops everything; `make down ARGS=--volumes` also forgets the data
	$(COMPOSE) down $(ARGS)

logs: ## Follows the logs; `make logs ARGS=api` for one service
	$(COMPOSE) logs --follow $(ARGS)

install: .env ## Installs the dependencies
	$(RUN) composer install

migrate: ## Creates the tables that do not exist yet
	$(COMPOSE) run --rm migrate

console: ## Runs a console command: `make console ARGS="projection:status"`
	$(COMPOSE) run --rm api bin/console $(ARGS)

shell: ## Opens a shell in the application container
	$(COMPOSE) run --rm api sh

token: ## Prints an access token of the development identity provider
	@$(COMPOSE) run --rm --no-deps api php docker/dev-token.php

test: ## Runs the tests, those against PostgreSQL included
	$(COMPOSE) run --rm -e TEST_DATABASE_DSN="pgsql:host=database;port=5432;dbname=app_test" api composer test

check: ## Runs everything the continuous integration runs
	$(COMPOSE) run --rm -e TEST_DATABASE_DSN="pgsql:host=database;port=5432;dbname=app_test" api composer check

cs-fix: ## Applies the coding standard
	$(RUN) composer cs:fix

rebuild: ## Empties a read model and replays the events into it: `make rebuild ARGS=banking_accounts`
	$(COMPOSE) run --rm api bin/console projection:rebuild $(ARGS) --force
