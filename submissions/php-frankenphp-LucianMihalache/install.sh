#!/usr/bin/env bash
# Runs once as root on a clean Ubuntu 24.04. Installs the runtime and nothing else: FrankenPHP (official release,
# glibc build), which is PHP and the Caddy web server in one program. Pinned by version and checked against its
# published SHA-256. Nothing on the box is tuned.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

FRANKENPHP_VERSION=1.13.0
FRANKENPHP_SHA256=19993b48e7833430dd44e0d053c9053a83d3561517bccad1da63c0086d398f5a

apt-get update
apt-get install -y --no-install-recommends ca-certificates curl

curl -fsSL -o /usr/local/bin/frankenphp \
  "https://github.com/php/frankenphp/releases/download/v${FRANKENPHP_VERSION}/frankenphp-linux-x86_64-gnu"
echo "${FRANKENPHP_SHA256}  /usr/local/bin/frankenphp" | sha256sum -c -
chmod 755 /usr/local/bin/frankenphp

frankenphp version
