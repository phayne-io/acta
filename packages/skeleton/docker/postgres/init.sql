-- Runs once, when the volume of the database is created.

-- The test suite of the application gets a database of its own.
CREATE DATABASE app_test OWNER app;

-- Keycloak keeps its realms, clients and users next to the application, apart from it.
CREATE USER keycloak WITH PASSWORD 'keycloak';
CREATE DATABASE keycloak OWNER keycloak;
