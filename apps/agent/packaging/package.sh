#!/bin/sh
#
# Build the CloudForge agent release archives consumed by install.sh.
#
# Asset names are stable so the installer can resolve the latest release:
#   cloudforge-agent_linux_amd64.tar.gz
#   cloudforge-agent_linux_arm64.tar.gz
#   SHA256SUMS
#
# Usage: VERSION=1.2.3 sh packaging/package.sh

set -eu

VERSION="${VERSION:-1.0.0-dev}"
DIST_DIR="${DIST_DIR:-dist}"
ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
UNIT_FILE="$ROOT_DIR/../../infrastructure/systemd/cloudforge-agent.service"

[ -f "$UNIT_FILE" ] || {
    printf 'packaging: missing systemd unit at %s\n' "$UNIT_FILE" >&2
    exit 1
}

rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

for arch in amd64 arm64; do
    stage="$(mktemp -d)"
    trap 'rm -rf "$stage"' EXIT HUP INT TERM

    printf 'packaging linux/%s (%s)\n' "$arch" "$VERSION"

    (
        cd "$ROOT_DIR"
        GOOS=linux GOARCH="$arch" CGO_ENABLED=0 go build \
            -trimpath \
            -ldflags "-s -w -X main.version=$VERSION" \
            -o "$stage/cloudforge-agent" \
            ./cmd/cloudforge-agent
    )

    cp "$UNIT_FILE" "$stage/cloudforge-agent.service"
    cp "$ROOT_DIR/README.md" "$stage/README.md"

    tar -czf "$DIST_DIR/cloudforge-agent_linux_${arch}.tar.gz" -C "$stage" \
        cloudforge-agent cloudforge-agent.service README.md

    rm -rf "$stage"
    trap - EXIT HUP INT TERM
done

if command -v sha256sum >/dev/null 2>&1; then
    (cd "$DIST_DIR" && sha256sum *.tar.gz >SHA256SUMS)
else
    (cd "$DIST_DIR" && shasum -a 256 *.tar.gz >SHA256SUMS)
fi

printf 'release archives written to %s\n' "$DIST_DIR"
cat "$DIST_DIR/SHA256SUMS"
