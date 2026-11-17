-- Runs once, when the volume of the database is created: the test suite gets a
-- database of its own, next to the one of the application.
CREATE DATABASE app_test OWNER app;
