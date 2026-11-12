#!/bin/sh
#
# Bootstrap a self-hosted CloudForge node.
#
# Deploy the Laravel control plane first (database migrated, web server
# running, APP_URL set to the public control plane URL). Then run this wrapper
# as root:
#
#   sudo sh infrastructure/scripts/bootstrap-node.sh
#
# It provisions the local bootstrap server, writes a short-lived enrollment
# credential, installs the agent, and removes the credential after the
# certificate has been issued. Re-running is safe: the server record is keyed
# by /etc/machine-id and is never duplicated.

set -eu

APP_DIR="${CLOUDFORGE_APP_DIR:-/var/www/cloudforge/apps/control-plane}"
PHP_USER="${CLOUDFORGE_PHP_USER:-www-data}"
CONFIG_DIR="${CLOUDFORGE_CONFIG_DIR:-/etc/cloudforge}"
CREDENTIAL="${CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH:-$CONFIG_DIR/bootstrap.env}"
STATUS="${CLOUDFORGE_BOOTSTRAP_STATUS_PATH:-$CONFIG_DIR/bootstrap.json}"
INSTALLER="${CLOUDFORGE_INSTALLER:-$APP_DIR/public/install.sh}"

say() {
    printf '%s\n' "cloudforge-bootstrap: $*"
}

fail() {
    printf '%s\n' "cloudforge-bootstrap: error: $*" >&2
    exit 1
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        fail "run this script as root (for example with sudo)"
    fi
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "required command '$1' was not found"
    fi
}

prepare_config_dir() {
    group="$(id -gn "$PHP_USER")"

    install -d -m 0770 -o root -g "$group" "$(dirname "$CREDENTIAL")" "$(dirname "$STATUS")"
}

run_artisan() {
    # Environment overrides are passed explicitly so a cached Laravel config
    # cannot silently redirect the credential or status files.
    if [ -n "${CLOUDFORGE_CONTROL_PLANE_URL:-}" ]; then
        runuser -u "$PHP_USER" -- env \
            "CLOUDFORGE_CONTROL_PLANE_URL=$CLOUDFORGE_CONTROL_PLANE_URL" \
            "CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH=$CREDENTIAL" \
            "CLOUDFORGE_BOOTSTRAP_STATUS_PATH=$STATUS" \
            "CLOUDFORGE_MACHINE_ID=${CLOUDFORGE_MACHINE_ID:-}" \
            php "$APP_DIR/artisan" "$@"
    else
        runuser -u "$PHP_USER" -- env \
            "CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH=$CREDENTIAL" \
            "CLOUDFORGE_BOOTSTRAP_STATUS_PATH=$STATUS" \
            "CLOUDFORGE_MACHINE_ID=${CLOUDFORGE_MACHINE_ID:-}" \
            php "$APP_DIR/artisan" "$@"
    fi
}

main() {
    require_root
    require_command php
    require_command runuser
    require_command install
    require_command getent

    [ -f "$APP_DIR/artisan" ] || fail "no artisan executable at $APP_DIR/artisan"
    [ -f "$INSTALLER" ] || fail "no installer at $INSTALLER"
    getent passwd "$PHP_USER" >/dev/null 2>&1 || fail "PHP user '$PHP_USER' does not exist"

    prepare_config_dir

    say "provisioning the local bootstrap server"

    if [ -n "${CLOUDFORGE_CONTROL_PLANE_URL:-}" ]; then
        run_artisan cloudforge:bootstrap --url "$CLOUDFORGE_CONTROL_PLANE_URL"
    else
        run_artisan cloudforge:bootstrap
    fi

    if [ -f "$CREDENTIAL" ]; then
        say "installing the agent with the single-use bootstrap credential"
        CLOUDFORGE_BOOTSTRAP=1 \
        CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH="$CREDENTIAL" \
            sh "$INSTALLER"
    else
        say "this host already holds an agent identity; refreshing the agent installation"

        if [ -f "$CONFIG_DIR/agent.env" ]; then
            set -a
            # shellcheck disable=SC1090
            . "$CONFIG_DIR/agent.env"
            set +a
        fi

        sh "$INSTALLER"
    fi

    say "bootstrap complete"
    run_artisan cloudforge:bootstrap:status || true
}

main "$@"
