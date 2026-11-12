#!/bin/sh
#
# CloudForge agent installer.
#
# Intended invocation (the control plane generates this command):
#
#   curl -fsSL https://cloudforge.example.com/install.sh | sudo \
#     env CLOUDFORGE_ENROLLMENT_TOKEN=... \
#         CLOUDFORGE_CONTROL_PLANE_URL=https://cloudforge.example.com \
#         CLOUDFORGE_SERVER_ID=... sh
#
# The enrollment token is used once and is never written to disk. Re-running
# the installer with an existing agent identity only refreshes the binary and
# unit; set CLOUDFORGE_FORCE_ENROLL=1 to replace the identity (the previous
# certificate must be revoked first).
#
# For a self-hosted CloudForge node the control plane runs
#
#   php artisan cloudforge:bootstrap
#
# first. That command writes a 0600 shell environment file containing the
# short-lived enrollment credential. This installer can consume it directly:
#
#   sudo env CLOUDFORGE_BOOTSTRAP=1 sh install.sh
#
# The credential is deleted as soon as enrollment succeeds. Set
# CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH to use a different path.
#
# Supported systems: Linux with systemd on amd64 or arm64.

set -eu

AGENT_USER="${CLOUDFORGE_AGENT_USER:-cloudforge-agent}"
INSTALL_DIR="${CLOUDFORGE_INSTALL_DIR:-/usr/local/bin}"
CONFIG_DIR="${CLOUDFORGE_CONFIG_DIR:-/etc/cloudforge}"
DATA_DIR="${CLOUDFORGE_DATA_DIR:-/var/lib/cloudforge-agent}"
DOCKER_SOCKET="${CLOUDFORGE_DOCKER_SOCKET:-/var/run/docker.sock}"
DOWNLOAD_BASE_URL="${CLOUDFORGE_DOWNLOAD_BASE_URL:-https://github.com/Muzamil-tech/cloudforge/releases/latest/download}"
SERVICE_NAME="cloudforge-agent"
BOOTSTRAP_MODE="${CLOUDFORGE_BOOTSTRAP:-0}"
BOOTSTRAP_CREDENTIAL="${CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH:-$CONFIG_DIR/bootstrap.env}"
BOOTSTRAP_CREDENTIAL_USED=0
AGENT_BINARY="${CLOUDFORGE_AGENT_BINARY:-}"

say() {
    printf '%s\n' "cloudforge-agent: $*"
}

fail() {
    printf '%s\n' "cloudforge-agent: error: $*" >&2
    exit 1
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        fail "run this installer as root (for example with sudo)"
    fi
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "required command '$1' was not found"
    fi
}

detect_arch() {
    case "$(uname -m)" in
        x86_64 | amd64)
            printf '%s' "amd64"
            ;;
        aarch64 | arm64)
            printf '%s' "arm64"
            ;;
        *)
            fail "unsupported architecture '$(uname -m)'; supported: amd64, arm64"
            ;;
    esac
}

shell_quote() {
    printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

expected_checksum() {
    # Accept the common checksum formats: "hash  file", "hash *file" and
    # "hash  ./*file". sha256sum writes the binary marker and a ./ prefix on
    # some platforms, so compare the final basename rather than raw field 2.
    awk -v name="$1" '
        {
            file = $2
            sub(/^\*/, "", file)
            sub(/^\.\//, "", file)

            if (file == name) {
                print $1
                exit
            }
        }
    ' "$2"
}

validate_agent_binary() {
    [ -n "$1" ] || fail "the local agent binary path is empty"

    [ -f "$1" ] || fail "the local agent binary $1 does not exist"

    [ -x "$1" ] || fail "the local agent binary $1 is not executable"
}

write_env_file() {
    umask 077
    {
        printf 'CLOUDFORGE_CONTROL_PLANE_URL=%s\n' "$(shell_quote "$CONTROL_PLANE_URL")"
        printf 'CLOUDFORGE_SERVER_ID=%s\n' "$(shell_quote "$CLOUDFORGE_SERVER_ID")"
        printf 'CLOUDFORGE_DATA_DIR=%s\n' "$(shell_quote "$DATA_DIR")"
        printf 'CLOUDFORGE_DOCKER_SOCKET=%s\n' "$(shell_quote "$DOCKER_SOCKET")"
        printf 'CLOUDFORGE_LOG_LEVEL=%s\n' "$(shell_quote "${CLOUDFORGE_LOG_LEVEL:-info}")"

        if [ -n "${CLOUDFORGE_CA_FILE:-}" ]; then
            printf 'CLOUDFORGE_CA_FILE=%s\n' "$(shell_quote "$CLOUDFORGE_CA_FILE")"
        fi

        if [ -n "${CLOUDFORGE_POSTGRES_IMAGE:-}" ]; then
            printf 'CLOUDFORGE_POSTGRES_IMAGE=%s\n' "$(shell_quote "$CLOUDFORGE_POSTGRES_IMAGE")"
        fi

        if [ -n "${CLOUDFORGE_REDIS_IMAGE:-}" ]; then
            printf 'CLOUDFORGE_REDIS_IMAGE=%s\n' "$(shell_quote "$CLOUDFORGE_REDIS_IMAGE")"
        fi
    } >"$CONFIG_DIR/agent.env"

    chown root:root "$CONFIG_DIR/agent.env"
    chmod 0600 "$CONFIG_DIR/agent.env"
}

load_bootstrap_credential() {
    # The control plane writes the token to a root-only shell environment file
    # so it never appears in process arguments, browser JavaScript, or logs.
    [ -f "$BOOTSTRAP_CREDENTIAL" ] ||
        fail "bootstrap credential $BOOTSTRAP_CREDENTIAL does not exist; run 'php artisan cloudforge:bootstrap' first"

    credential_mode="$(stat -c '%a' "$BOOTSTRAP_CREDENTIAL" 2>/dev/null || printf 'unknown')"

    if [ "$credential_mode" != "600" ]; then
        fail "bootstrap credential $BOOTSTRAP_CREDENTIAL must have mode 0600 (found $credential_mode)"
    fi

    set -a
    # shellcheck disable=SC1090
    . "$BOOTSTRAP_CREDENTIAL"
    set +a

    BOOTSTRAP_CREDENTIAL_USED=1
}

remove_bootstrap_credential() {
    if [ "$BOOTSTRAP_CREDENTIAL_USED" != "1" ]; then
        return 0
    fi

    rm -f "$BOOTSTRAP_CREDENTIAL"
    say "removed the single-use bootstrap credential $BOOTSTRAP_CREDENTIAL"
}

install_unit() {
    # The unit is rendered with the resolved paths so custom install, config,
    # data and Docker socket locations stay consistent with the files on disk.
    cat >"/etc/systemd/system/$SERVICE_NAME.service" <<UNIT
[Unit]
Description=CloudForge Agent
Documentation=https://github.com/Muzamil-tech/cloudforge
After=network-online.target docker.service
Wants=network-online.target
ConditionPathExists=$CONFIG_DIR/agent.env

[Service]
Type=simple
User=$AGENT_USER
Group=$AGENT_USER
EnvironmentFile=$CONFIG_DIR/agent.env
ExecStart=$INSTALL_DIR/cloudforge-agent run
Restart=always
RestartSec=5
TimeoutStopSec=30
KillSignal=SIGTERM
UMask=0077
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$DATA_DIR -$DOCKER_SOCKET
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
LockPersonality=yes
MemoryDenyWriteExecute=yes
SystemCallArchitectures=native

[Install]
WantedBy=multi-user.target
UNIT

    chmod 0644 "/etc/systemd/system/$SERVICE_NAME.service"
}

main() {
    require_root

    if [ "$BOOTSTRAP_MODE" = "1" ] && [ -z "${CLOUDFORGE_ENROLLMENT_TOKEN:-}" ]; then
        load_bootstrap_credential
    fi

    [ -n "${CLOUDFORGE_CONTROL_PLANE_URL:-}" ] || fail "CLOUDFORGE_CONTROL_PLANE_URL is required"
    [ -n "${CLOUDFORGE_SERVER_ID:-}" ] || fail "CLOUDFORGE_SERVER_ID is required"

    require_command systemctl
    require_command runuser
    require_command useradd
    require_command getent

    CONTROL_PLANE_URL="$(printf '%s' "$CLOUDFORGE_CONTROL_PLANE_URL" | sed 's:/*$::')"

    case "$CONTROL_PLANE_URL" in
        https://* | http://localhost* | http://127.0.0.1* | http://\[::1\]*)
            ;;
        *)
            fail "CLOUDFORGE_CONTROL_PLANE_URL must use https (plain http is only allowed for loopback development)"
            ;;
    esac

    if ! systemctl --version >/dev/null 2>&1; then
        fail "systemd is required on the target host"
    fi

    arch="$(detect_arch)"
    agent_source=""

    if [ -n "$AGENT_BINARY" ]; then
        validate_agent_binary "$AGENT_BINARY"
        agent_source="$AGENT_BINARY"
        say "using the prebuilt agent binary at $AGENT_BINARY"
    else
        require_command curl
        require_command tar

        archive="cloudforge-agent_linux_${arch}.tar.gz"
        checksums="SHA256SUMS"

        say "installing for linux/${arch} from ${DOWNLOAD_BASE_URL}"

        temp_dir="$(mktemp -d)"
        trap 'rm -rf "$temp_dir"' EXIT HUP INT TERM

        curl -fsSL "${DOWNLOAD_BASE_URL}/${archive}" -o "$temp_dir/$archive" ||
            fail "failed to download ${DOWNLOAD_BASE_URL}/${archive}"

        curl -fsSL "${DOWNLOAD_BASE_URL}/${checksums}" -o "$temp_dir/$checksums" ||
            fail "failed to download checksums from ${DOWNLOAD_BASE_URL}/${checksums}"

        expected="$(expected_checksum "$archive" "$temp_dir/$checksums")"
        [ -n "$expected" ] || fail "no checksum found for $archive in $checksums"

        if command -v sha256sum >/dev/null 2>&1; then
            actual="$(sha256sum "$temp_dir/$archive" | awk '{ print $1 }')"
        elif command -v shasum >/dev/null 2>&1; then
            actual="$(shasum -a 256 "$temp_dir/$archive" | awk '{ print $1 }')"
        else
            fail "neither sha256sum nor shasum is available to verify the download"
        fi

        [ "$actual" = "$expected" ] || fail "checksum verification failed for $archive"

        tar -xzf "$temp_dir/$archive" -C "$temp_dir"

        [ -f "$temp_dir/cloudforge-agent" ] || fail "the release archive does not contain cloudforge-agent"

        agent_source="$temp_dir/cloudforge-agent"
    fi

    if ! getent passwd "$AGENT_USER" >/dev/null 2>&1; then
        nologin="$(command -v nologin || printf '/usr/sbin/nologin')"
        useradd --system --home-dir "$DATA_DIR" --shell "$nologin" --user-group "$AGENT_USER"
        say "created system user $AGENT_USER"
    fi

    install -d -m 0755 "$INSTALL_DIR"

    if [ ! -d "$CONFIG_DIR" ]; then
        install -d -m 0750 "$CONFIG_DIR"
    fi

    install -d -m 0750 -o "$AGENT_USER" -g "$AGENT_USER" "$DATA_DIR"

    install -m 0755 "$agent_source" "$INSTALL_DIR/cloudforge-agent"

    if getent group docker >/dev/null 2>&1; then
        usermod -aG docker "$AGENT_USER"
        say "added $AGENT_USER to the docker group for read-only daemon inspection"
    else
        say "docker group not found; Docker inventory will report unavailable until the socket is readable"
    fi

    write_env_file
    install_unit

    if [ -f "$DATA_DIR/agent.key" ] && [ "${CLOUDFORGE_FORCE_ENROLL:-0}" != "1" ]; then
        say "existing agent identity found; skipping enrollment (set CLOUDFORGE_FORCE_ENROLL=1 to replace it)"
    else
        [ -n "${CLOUDFORGE_ENROLLMENT_TOKEN:-}" ] ||
            fail "CLOUDFORGE_ENROLLMENT_TOKEN is required to enroll this server"

        say "redeeming the single-use enrollment token"
        runuser -u "$AGENT_USER" -- env \
            CLOUDFORGE_CONTROL_PLANE_URL="$CONTROL_PLANE_URL" \
            CLOUDFORGE_SERVER_ID="$CLOUDFORGE_SERVER_ID" \
            CLOUDFORGE_MACHINE_ID="${CLOUDFORGE_MACHINE_ID:-}" \
            CLOUDFORGE_DATA_DIR="$DATA_DIR" \
            CLOUDFORGE_DOCKER_SOCKET="$DOCKER_SOCKET" \
            CLOUDFORGE_CA_FILE="${CLOUDFORGE_CA_FILE:-}" \
            CLOUDFORGE_ENROLLMENT_TOKEN="$CLOUDFORGE_ENROLLMENT_TOKEN" \
            CLOUDFORGE_LOG_LEVEL="${CLOUDFORGE_LOG_LEVEL:-info}" \
            "$INSTALL_DIR/cloudforge-agent" enroll ${CLOUDFORGE_FORCE_ENROLL:+--force}
    fi

    remove_bootstrap_credential

    systemctl daemon-reload
    systemctl enable "$SERVICE_NAME"
    systemctl restart "$SERVICE_NAME"

    say "agent installed and started; inspect it with: systemctl status $SERVICE_NAME"
    say "operator note: the enrollment token was not written to $CONFIG_DIR/agent.env"
}

if [ "${CLOUDFORGE_INSTALLER_TEST_MODE:-0}" = "1" ]; then
    # Sourced by tests/shell/installer_test.sh to exercise individual
    # functions. Production invocations always run main.
    :
else
    main "$@"
fi
