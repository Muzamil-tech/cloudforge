#!/usr/bin/env bash
#
# Contract tests for the one-command CloudForge control-plane installer.
#
# Run on a host with Bash:
#
#   bash tests/shell/one_command_installer_test.sh [path-to-install.sh]
#
# The production installer requires root, apt, systemd, PostgreSQL, Redis,
# Nginx and network access. These tests source it with
# CLOUDFORGE_INSTALLER_TEST_MODE=1 and exercise the pure configuration helpers.

set -u

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
INSTALLER=${1:-${CLOUDFORGE_INSTALLER:-$SCRIPT_DIR/../../install.sh}}

if [ ! -f "$INSTALLER" ]; then
    printf 'not ok - installer not found at %s\n' "$INSTALLER" >&2
    exit 1
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT HUP INT TERM

assertions=0
failures=0

ok() {
    assertions=$((assertions + 1))
    printf 'ok %d - %s\n' "$assertions" "$1"
}

not_ok() {
    assertions=$((assertions + 1))
    failures=$((failures + 1))
    printf 'not ok %d - %s\n' "$assertions" "$1"
}

assert_eq() {
    if [ "$2" = "$3" ]; then
        ok "$1"
    else
        not_ok "$1: expected [$2], got [$3]"
    fi
}

assert_contains() {
    case "$3" in
        *"$2"*)
            ok "$1"
            ;;
        *)
            not_ok "$1: [$2] not found in [$3]"
            ;;
    esac
}

source_installer() {
    CLOUDFORGE_INSTALLER_TEST_MODE=1
    export CLOUDFORGE_INSTALLER_TEST_MODE
    # shellcheck disable=SC1090
    . "$INSTALLER"
    set +e
}

# --- domain validation ----------------------------------------------------

case_valid_domain() {
    source_installer
    is_valid_domain "cloud.example.com"
}

output=$( (case_valid_domain) 2>&1)
assert_eq "a normal FQDN is accepted" 0 "$?"

case_invalid_domain() {
    source_installer
    is_valid_domain "cloud_example"
}

output=$( (case_invalid_domain) 2>&1)
status=$?
assert_eq "an underscore hostname is rejected" 1 "$status"

case_single_label() {
    source_installer
    is_valid_domain "localhost"
}

output=$( (case_single_label) 2>&1)
status=$?
assert_eq "a single-label hostname is rejected" 1 "$status"

case_uppercase_domain() {
    source_installer
    is_valid_domain "Cloud.Example.COM"
}

output=$( (case_uppercase_domain) 2>&1)
assert_eq "domain validation is case-insensitive" 0 "$?"

# --- .env value handling --------------------------------------------------

append_env="$WORK/env-append"
: >"$append_env"

case_env_append() {
    source_installer
    set_env_value FOO "bar/baz&qux" "$append_env"
}

output=$( (case_env_append) 2>&1)
status=$?
assert_eq "a missing environment key is appended" 0 "$status"
assert_eq "the appended value is preserved" "FOO=bar/baz&qux" "$(cat "$append_env")"

case_env_replace() {
    source_installer
    set_env_value FOO "next&value|with-specials" "$append_env"
}

output=$( (case_env_replace) 2>&1)
status=$?
assert_eq "an existing environment key is replaced" 0 "$status"
assert_eq "special characters survive replacement" \
    "FOO=next&value|with-specials" "$(cat "$append_env")"

read_env="$WORK/env-read"
printf '%s\n' 'APP_KEY="base64:example"' >"$read_env"

case_env_read() {
    source_installer
    env_value APP_KEY "$read_env"
}

value=$( (case_env_read) 2>&1)
assert_eq "quoted environment values are read without quotes" "base64:example" "$value"

# --- architecture detection ----------------------------------------------

case_amd64() {
    source_installer
    uname() { printf 'x86_64'; }
    detect_arch
}

arch=$( (case_amd64) 2>&1)
assert_eq "x86_64 maps to amd64" "amd64" "$arch"

case_arm64() {
    source_installer
    uname() { printf 'aarch64'; }
    detect_arch
}

arch=$( (case_arm64) 2>&1)
assert_eq "aarch64 maps to arm64" "arm64" "$arch"

case_unsupported() {
    source_installer
    uname() { printf 'sparc64'; }
    detect_arch
}

output=$( (case_unsupported) 2>&1)
status=$?
assert_eq "an unsupported architecture is rejected" 1 "$status"

# --- final environment ----------------------------------------------------

https_env="$WORK/env-https"
printf '%s\n' 'APP_URL=http://old.example' 'SESSION_SECURE_COOKIE=false' >"$https_env"

case_finalize_https() {
    source_installer
    ENV_FILE="$https_env"
    DOMAIN="cloud.example.com"
    APP_HOST="cloud.example.com"
    TLS_ACTIVE=1
    finalize_environment
    printf 'app_url=%s\n' "$(env_value APP_URL "$ENV_FILE")"
    printf 'secure=%s\n' "$(env_value SESSION_SECURE_COOKIE "$ENV_FILE")"
}

output=$( (case_finalize_https) 2>&1)
assert_contains "HTTPS installations use an https APP_URL" \
    "app_url=https://cloud.example.com" "$output"
assert_contains "HTTPS installations mark sessions secure" "secure=true" "$output"

http_env="$WORK/env-http"
printf '%s\n' 'APP_URL=https://old.example' 'SESSION_SECURE_COOKIE=true' >"$http_env"

case_finalize_http() {
    source_installer
    ENV_FILE="$http_env"
    DOMAIN=""
    APP_HOST="203.0.113.10"
    TLS_ACTIVE=0
    finalize_environment
    printf 'app_url=%s\n' "$(env_value APP_URL "$ENV_FILE")"
    printf 'secure=%s\n' "$(env_value SESSION_SECURE_COOKIE "$ENV_FILE")"
}

output=$( (case_finalize_http) 2>&1)
assert_contains "HTTP installations use an http APP_URL" \
    "app_url=http://203.0.113.10" "$output"
assert_contains "HTTP installations disable secure cookies" "secure=false" "$output"

# --- nginx server block ---------------------------------------------------

case_nginx_https() {
    source_installer
    APP_ROOT="/var/www/cloudforge/apps/control-plane"
    DOMAIN="cloud.example.com"
    PHP_SOCK="/run/php/php8.4-fpm.sock"
    nginx_app_server "443 ssl" "cloud.example.com" "1"
}

output=$( (case_nginx_https) 2>&1)
assert_contains "the TLS server block listens on 443" "listen 443 ssl;" "$output"
assert_contains "the TLS server block uses the requested certificate" \
    "/etc/letsencrypt/live/cloud.example.com/fullchain.pem" "$output"
assert_contains "the PHP location uses the detected socket" \
    "fastcgi_pass unix:/run/php/php8.4-fpm.sock;" "$output"
assert_contains "the front controller fallback is preserved" \
    'try_files $uri $uri/ /index.php?$query_string;' "$output"

# --- result ---------------------------------------------------------------

printf '\n%d assertions, %d failures\n' "$assertions" "$failures"

if [ "$failures" -ne 0 ]; then
    exit 1
fi

exit 0
