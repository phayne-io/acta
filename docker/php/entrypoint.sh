#!/bin/sh
# Adds the mkcert authority, mounted in /usr/local/share/ca-certificates, to the
# certificates the container trusts: PHP then reaches https://auth.mybank.io like any
# other HTTPS address, with verification on.
set -e

update-ca-certificates 2>/dev/null

exec docker-php-entrypoint "$@"
