#!/usr/bin/env bash
# Runs once as root on a clean Ubuntu 24.04. Installs the runtime and nothing else:
#   - FrankenPHP (official release, glibc build): PHP and the Caddy web server in one program
#   - Composer (official phar): build.sh uses it to fetch the pinned PHP packages
# Both are pinned by version and checked against their published SHA-256. Nothing on the box is tuned.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

FRANKENPHP_VERSION=1.13.0
FRANKENPHP_SHA256=19993b48e7833430dd44e0d053c9053a83d3561517bccad1da63c0086d398f5a
COMPOSER_VERSION=2.10.3
COMPOSER_SHA256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6

apt-get update
apt-get install -y --no-install-recommends ca-certificates curl unzip

curl -fsSL -o /usr/local/bin/frankenphp \
  "https://github.com/php/frankenphp/releases/download/v${FRANKENPHP_VERSION}/frankenphp-linux-x86_64-gnu"
echo "${FRANKENPHP_SHA256}  /usr/local/bin/frankenphp" | sha256sum -c -
chmod 755 /usr/local/bin/frankenphp

curl -fsSL -o /usr/local/bin/composer.phar \
  "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"
echo "${COMPOSER_SHA256}  /usr/local/bin/composer.phar" | sha256sum -c -
chmod 644 /usr/local/bin/composer.phar

frankenphp version
