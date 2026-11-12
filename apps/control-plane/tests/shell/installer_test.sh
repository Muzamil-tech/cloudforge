#!/bin/sh
#
# Contract tests for public/install.sh.
#
# Run on a POSIX host:
#
#   sh tests/shell/installer_test.sh [path-to-install.sh]
#
# The production script requires root, systemd, network access and a Docker
# host, so these tests source it with CLOUDFORGE_INSTALLER_TEST_MODE=1 and
# exercise the security boundaries that must hold on every host:
#
#   * a bootstrap credential is refused unless it is exactly mode 0600
#   * a missing or unreadable credential fails before any mutation
#   * the enrollment token is never written to agent.env
#   * the credential file is deleted after a successful enrollment
#   * vendor checksum formats are parsed without weakening verification
#
# Filesystem mode assertions are skipped on hosts that do not preserve POSIX
# modes (for example Git Bash on Windows), so the same suite runs unchanged on
# Linux CI and on a developer workstation.

set -u

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
INSTALLER=${1:-${CLOUDFORGE_INSTALLER:-$SCRIPT_DIR/../../public/install.sh}}

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

skip() {
    assertions=$((assertions + 1))
    printf 'ok %d - %s # skip %s\n' "$assertions" "$1" "${2:-}"
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
        *"$2"*) ok "$1" ;;
        *) not_ok "$1: [$2] not found in [$3]" ;;
    esac
}

assert_not_contains() {
    case "$3" in
        *"$2"*) not_ok "$1: [$2] unexpectedly present in [$3]" ;;
        *) ok "$1" ;;
    esac
}

source_installer() {
    CLOUDFORGE_INSTALLER_TEST_MODE=1
    export CLOUDFORGE_INSTALLER_TEST_MODE
    # shellcheck disable=SC1090
    . "$INSTALLER"
    set +e
}

# --- credential mode gate -------------------------------------------------

case_missing_credential() {
    credential="$WORK/missing-credential.env"
    rm -f "$credential"
    CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH="$credential"
    export CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH
    source_installer
    load_bootstrap_credential
}

output=$( (case_missing_credential) 2>&1)
status=$?
assert_eq "missing bootstrap credential is rejected" 1 "$status"
assert_contains "missing credential error names the path" \
    "bootstrap credential $WORK/missing-credential.env does not exist" "$output"

case_world_readable_credential() {
    credential="$WORK/credential-0644.env"
    printf '%s\n' 'CLOUDFORGE_ENROLLMENT_TOKEN=secret' >"$credential"
    stat() { printf '644'; }
    CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH="$credential"
    export CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH
    source_installer
    load_bootstrap_credential
}

output=$( (case_world_readable_credential) 2>&1)
status=$?
assert_eq "credential with mode 0644 is rejected" 1 "$status"
assert_contains "mode rejection reports the found mode" \
    "must have mode 0600 (found 644)" "$output"

case_unreadable_mode_credential() {
    credential="$WORK/credential-unknown.env"
    printf '%s\n' 'CLOUDFORGE_ENROLLMENT_TOKEN=secret' >"$credential"
    stat() { return 1; }
    CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH="$credential"
    export CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH
    source_installer
    load_bootstrap_credential
}

output=$( (case_unreadable_mode_credential) 2>&1)
status=$?
assert_eq "credential is rejected when its mode cannot be read" 1 "$status"
assert_contains "unreadable mode is reported explicitly" \
    "must have mode 0600 (found unknown)" "$output"

case_valid_credential() {
    credential="$WORK/credential-0600.env"
    cat >"$credential" <<CREDENTIAL
CLOUDFORGE_CONTROL_PLANE_URL='https://cloud.example.test'
CLOUDFORGE_SERVER_ID='server-01'
CLOUDFORGE_ENROLLMENT_TOKEN='token-from-credential'
CLOUDFORGE_DATA_DIR='$WORK/data'
CREDENTIAL

    stat() { printf '600'; }
    CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH="$credential"
    export CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH
    source_installer
    load_bootstrap_credential
    printf 'token=%s\n' "${CLOUDFORGE_ENROLLMENT_TOKEN:-}"
    printf 'server=%s\n' "${CLOUDFORGE_SERVER_ID:-}"
    printf 'used=%s\n' "${BOOTSTRAP_CREDENTIAL_USED:-}"
}

output=$( (case_valid_credential) 2>&1)
status=$?
assert_eq "credential with mode 0600 is accepted" 0 "$status"
assert_contains "credential token is exported to the environment" "token=token-from-credential" "$output"
assert_contains "credential server id is exported to the environment" "server=server-01" "$output"
assert_contains "accepted credential is marked as used" "used=1" "$output"

# --- agent.env contents ---------------------------------------------------

case_write_env_file() {
    CLOUDFORGE_CONFIG_DIR="$WORK/config"
    CLOUDFORGE_DATA_DIR="$WORK/data"
    CLOUDFORGE_DOCKER_SOCKET='/var/run/docker.sock'
    CLOUDFORGE_ENROLLMENT_TOKEN='super-secret-token'
    export CLOUDFORGE_CONFIG_DIR CLOUDFORGE_DATA_DIR CLOUDFORGE_DOCKER_SOCKET CLOUDFORGE_ENROLLMENT_TOKEN

    mkdir -p "$WORK/config"

    # write_env_file pins root ownership in production. The test runs as the
    # invoking user, so ownership is stubbed while chmod stays real.
    chown() { :; }

    source_installer

    CONTROL_PLANE_URL='https://cloud.example.test'
    SERVER_ID='server-01'
    write_env_file
}

output=$( (case_write_env_file) 2>&1)
status=$?
agent_env="$WORK/config/agent.env"

assert_eq "agent.env is written" 0 "$status"
if [ -f "$agent_env" ]; then
    contents=$(cat "$agent_env")

    assert_contains "agent.env records the control plane URL" \
        "CLOUDFORGE_CONTROL_PLANE_URL='https://cloud.example.test'" "$contents"
    assert_contains "agent.env records the server id" \
        "CLOUDFORGE_SERVER_ID='server-01'" "$contents"
    assert_not_contains "agent.env never contains the enrollment token value" \
        "super-secret-token" "$contents"
    assert_not_contains "agent.env never contains an enrollment token key" \
        "CLOUDFORGE_ENROLLMENT_TOKEN" "$contents"

    mode_probe="$WORK/mode-probe"
    : >"$mode_probe"
    chmod 600 "$mode_probe" 2>/dev/null || true
    probe_mode=$(command stat -c '%a' "$mode_probe" 2>/dev/null || printf 'unknown')

    if [ "$probe_mode" = "600" ]; then
        env_mode=$(command stat -c '%a' "$agent_env" 2>/dev/null || printf 'unknown')
        assert_eq "agent.env is mode 0600" 600 "$env_mode"
    else
        skip "agent.env is mode 0600" "filesystem reports mode $probe_mode"
    fi
else
    not_ok "agent.env is written: file is missing"
fi

# --- credential deletion --------------------------------------------------

case_remove_used_credential() {
    credential="$WORK/credential-used.env"
    printf '%s\n' 'CLOUDFORGE_ENROLLMENT_TOKEN=secret' >"$credential"
    CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH="$credential"
    export CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH
    source_installer
    BOOTSTRAP_CREDENTIAL_USED=1
    remove_bootstrap_credential
}

output=$( (case_remove_used_credential) 2>&1)
status=$?
assert_eq "credential removal succeeds" 0 "$status"
assert_contains "credential removal is reported" \
    "removed the single-use bootstrap credential" "$output"
if [ -f "$WORK/credential-used.env" ]; then
    not_ok "used bootstrap credential is deleted"
else
    ok "used bootstrap credential is deleted"
fi

case_keep_unused_credential() {
    credential="$WORK/credential-unused.env"
    printf '%s\n' 'CLOUDFORGE_ENROLLMENT_TOKEN=secret' >"$credential"
    CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH="$credential"
    export CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH
    source_installer
    BOOTSTRAP_CREDENTIAL_USED=0
    remove_bootstrap_credential
}

output=$( (case_keep_unused_credential) 2>&1)
status=$?
assert_eq "credential removal is a no-op when nothing was loaded" 0 "$status"
if [ -f "$WORK/credential-unused.env" ]; then
    ok "unused bootstrap credential is preserved"
else
    not_ok "unused bootstrap credential is preserved"
fi

# --- checksum parsing -----------------------------------------------------

CHECKSUMS="$WORK/SHA256SUMS"
cat >"$CHECKSUMS" <<'SUMS'
aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  cloudforge-agent_linux_amd64.tar.gz
bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb *./cloudforge-agent_linux_arm64.tar.gz
cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc  ./unrelated.tar.gz
SUMS

case_checksum_plain() {
    source_installer
    expected_checksum "cloudforge-agent_linux_amd64.tar.gz" "$CHECKSUMS"
}

checksum=$( (case_checksum_plain) 2>&1)
assert_eq "plain SHA256SUMS entries are parsed" \
    "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" "$checksum"

case_checksum_binary_prefix() {
    source_installer
    expected_checksum "cloudforge-agent_linux_arm64.tar.gz" "$CHECKSUMS"
}

checksum=$( (case_checksum_binary_prefix) 2>&1)
assert_eq "SHA256SUMS binary and ./ prefixes are parsed" \
    "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" "$checksum"

case_checksum_missing() {
    source_installer
    expected_checksum "cloudforge-agent_linux_riscv64.tar.gz" "$CHECKSUMS"
}

checksum=$( (case_checksum_missing) 2>&1)
assert_eq "unknown archives yield no checksum" "" "$checksum"

# --- quoting and host checks ---------------------------------------------

case_shell_quote() {
    source_installer
    shell_quote "it's a 'quoted' value"
}

quoted=$( (case_shell_quote) 2>&1)
if eval "unquoted=$quoted" 2>/dev/null; then
    assert_eq "shell_quote survives apostrophes and spaces" "it's a 'quoted' value" "$unquoted"
else
    not_ok "shell_quote survives apostrophes and spaces: could not evaluate [$quoted]"
fi

case_detect_amd64() {
    source_installer
    uname() { printf 'x86_64'; }
    detect_arch
}

arch=$( (case_detect_amd64) 2>&1)
assert_eq "x86_64 maps to amd64" "amd64" "$arch"

case_detect_arm64() {
    source_installer
    uname() { printf 'aarch64'; }
    detect_arch
}

arch=$( (case_detect_arm64) 2>&1)
assert_eq "aarch64 maps to arm64" "arm64" "$arch"

case_detect_unsupported() {
    source_installer
    uname() { printf 'sparc64'; }
    detect_arch
}

output=$( (case_detect_unsupported) 2>&1)
status=$?
assert_eq "unsupported architecture is rejected" 1 "$status"
assert_contains "unsupported architecture error lists supported values" \
    "supported: amd64, arm64" "$output"

case_require_non_root() {
    source_installer
    id() { printf '1000\n'; }
    require_root
}

output=$( (case_require_non_root) 2>&1)
status=$?
assert_eq "non-root installation is rejected" 1 "$status"
assert_contains "non-root error explains how to retry" \
    "run this installer as root" "$output"

# --- result ---------------------------------------------------------------

printf '\n%d assertions, %d failures\n' "$assertions" "$failures"

if [ "$failures" -ne 0 ]; then
    exit 1
fi

exit 0
