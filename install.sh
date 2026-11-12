#!/usr/bin/env bash
#
# CloudForge one-command installer for a clean Ubuntu or Debian VPS.
#
# Secure install:
#   curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
#     | sudo CLOUDFORGE_DOMAIN=cloud.example.com \
#            CLOUDFORGE_ACME_EMAIL=you@example.com \
#            bash
#
# Development install without TLS (the agent enrolls over loopback):
#   curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
#     | sudo CLOUDFORGE_HTTP_ONLY=1 bash
#
# The installer is idempotent: re-running it updates the application, preserves
# .env secrets and the PostgreSQL role, and re-runs migrations.
#
# It never prints or logs secret values. The database password is generated
# locally and stored only in the application .env.

set -Eeuo pipefail

APP_DIR="${CLOUDFORGE_APP_DIR:-/var/www/cloudforge}"
APP_ROOT="${APP_DIR}/apps/control-plane"
ENV_FILE="${APP_ROOT}/.env"

REPO_URL="${CLOUDFORGE_REPO_URL:-https://github.com/Muzamil-tech/cloudforge.git}"
REPO_REF="${CLOUDFORGE_REF:-main}"
REPO_TOKEN="${CLOUDFORGE_REPO_TOKEN:-}"

DOMAIN="${CLOUDFORGE_DOMAIN:-}"
ACME_EMAIL="${CLOUDFORGE_ACME_EMAIL:-}"
HTTP_ONLY="${CLOUDFORGE_HTTP_ONLY:-0}"
OPEN_REGISTRATION="${CLOUDFORGE_OPEN_REGISTRATION:-0}"
SKIP_AGENT="${CLOUDFORGE_SKIP_AGENT:-0}"

PHP_VERSION="${CLOUDFORGE_PHP_VERSION:-8.4}"
NODE_MAJOR="${CLOUDFORGE_NODE_MAJOR:-22}"
GO_VERSION="${CLOUDFORGE_GO_VERSION:-1.24.6}"
INSTALL_DOCKER="${CLOUDFORGE_INSTALL_DOCKER:-1}"

DB_NAME="${CLOUDFORGE_DB_NAME:-cloudforge}"
DB_USER="${CLOUDFORGE_DB_USER:-cloudforge}"
DB_PASSWORD=""

PUBLIC_IP_OVERRIDE="${CLOUDFORGE_PUBLIC_IP:-}"
PUBLIC_IP=""
APP_HOST=""
SERVER_NAME=""
TLS_ACTIVE=0
PHP_BIN=""
PHP_SOCK=""

AGENT_BINARY_INPUT="${CLOUDFORGE_AGENT_BINARY:-}"
AGENT_BINARY=""
GO_ROOT="/usr/local/cloudforge/go"

LOG_FILE="${CLOUDFORGE_INSTALL_LOG:-/var/log/cloudforge-install.log}"

say() {
    printf 'cloudforge-install: %s\n' "$*"
}

warn() {
    printf 'cloudforge-install: warning: %s\n' "$*" >&2
}

fail() {
    printf 'cloudforge-install: error: %s\n' "$*" >&2
    exit 1
}

on_error() {
    local status=$?

    printf 'cloudforge-install: error: command failed with exit %s. See %s\n' "$status" "$LOG_FILE" >&2
    exit "$status"
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

is_valid_domain() {
    printf '%s' "$1" | grep -Eq '^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$'
}

load_os() {
    [ -r /etc/os-release ] || fail "/etc/os-release is missing; this installer supports Ubuntu and Debian"

    # shellcheck disable=SC1091
    . /etc/os-release

    OS_ID="${ID:-}"
    OS_CODENAME="${VERSION_CODENAME:-}"

    case "$OS_ID" in
        ubuntu | debian)
            ;;
        *)
            fail "unsupported operating system '$OS_ID'; supported: Ubuntu 22.04/24.04 and Debian 12"
            ;;
    esac

    [ -n "$OS_CODENAME" ] || fail "could not determine the operating-system codename"
    say "detected ${PRETTY_NAME:-$OS_ID $OS_CODENAME}"
}

validate_config() {
    case "$HTTP_ONLY" in
        0 | 1)
            ;;
        *)
            fail "CLOUDFORGE_HTTP_ONLY must be 0 or 1"
            ;;
    esac

    case "$OPEN_REGISTRATION" in
        0 | 1)
            ;;
        *)
            fail "CLOUDFORGE_OPEN_REGISTRATION must be 0 or 1"
            ;;
    esac

    case "$SKIP_AGENT" in
        0 | 1)
            ;;
        *)
            fail "CLOUDFORGE_SKIP_AGENT must be 0 or 1"
            ;;
    esac

    case "$INSTALL_DOCKER" in
        0 | 1)
            ;;
        *)
            fail "CLOUDFORGE_INSTALL_DOCKER must be 0 or 1"
            ;;
    esac

    if [ -n "$DOMAIN" ]; then
        DOMAIN="$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]')"

        is_valid_domain "$DOMAIN" ||
            fail "CLOUDFORGE_DOMAIN '$DOMAIN' is not a valid hostname"
    fi

    if [ "$HTTP_ONLY" != "1" ]; then
        [ -n "$DOMAIN" ] ||
            fail "set CLOUDFORGE_DOMAIN and CLOUDFORGE_ACME_EMAIL, or set CLOUDFORGE_HTTP_ONLY=1 for a development install"

        [ -n "$ACME_EMAIL" ] ||
            fail "CLOUDFORGE_ACME_EMAIL is required for automatic HTTPS"

        case "$ACME_EMAIL" in
            *@*.*)
                ;;
            *)
                fail "CLOUDFORGE_ACME_EMAIL '$ACME_EMAIL' is not a valid email address"
                ;;
        esac
    fi

    say "configuration validated"
}

setup_logging() {
    mkdir -p "$(dirname "$LOG_FILE")"
    touch "$LOG_FILE"
    chmod 0600 "$LOG_FILE"

    exec > >(tee -a "$LOG_FILE") 2>&1
}

install_base_packages() {
    export DEBIAN_FRONTEND=noninteractive

    say "installing base packages"
    wait_for_apt_lock

    # Recover a dpkg state left by an interrupted first-boot upgrade.
    dpkg --configure -a >/dev/null 2>&1 || true

    apt_get update
    apt_get install -y --no-install-recommends \
        apt-transport-https \
        ca-certificates \
        cron \
        certbot \
        curl \
        git \
        gnupg \
        lsb-release \
        nginx \
        openssl \
        postgresql \
        python3-certbot-nginx \
        redis-server \
        software-properties-common \
        unzip \
        zip

    systemctl enable --now cron postgresql redis-server nginx
}

apt_get() {
    apt-get -o DPkg::Lock::Timeout=600 "$@"
}

wait_for_apt_lock() {
    local attempts=0

    say "waiting for the package manager lock"

    # A fresh Ubuntu host often starts unattended-upgrades during the first
    # boot. --no-block prevents systemctl from hanging if the service is stuck
    # waiting on a slow mirror.
    systemctl stop --no-block unattended-upgrades.service 2>/dev/null || true
    systemctl stop --no-block apt-daily.service apt-daily-upgrade.service 2>/dev/null || true

    while pgrep -f 'unattended-upgr|apt-get|/dpkg( |$)' >/dev/null 2>&1; do
        attempts=$((attempts + 1))

        if [ "$attempts" -ge 300 ]; then
            fail "the package manager is still locked; stop unattended-upgrades and retry"
        fi

        if [ $((attempts % 15)) -eq 0 ]; then
            say "still waiting for the package manager lock (${attempts}s)"
        fi

        sleep 2
    done
}

install_php() {
    say "installing PHP ${PHP_VERSION}"

    wait_for_apt_lock

    if [ "$OS_ID" = "ubuntu" ]; then
        add-apt-repository -y ppa:ondrej/php
    else
        install -d -m 0755 /usr/share/keyrings
        curl -fsSL https://packages.sury.org/php/apt.gpg \
            -o /usr/share/keyrings/sury-php.gpg
        printf 'deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ %s main\n' \
            "$OS_CODENAME" >/etc/apt/sources.list.d/sury-php.list
    fi

    apt_get update
    apt_get install -y --no-install-recommends \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-fpm" \
        "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-opcache" \
        "php${PHP_VERSION}-pgsql" \
        "php${PHP_VERSION}-redis" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-zip"

    PHP_BIN="/usr/bin/php${PHP_VERSION}"

    [ -x "$PHP_BIN" ] || fail "PHP ${PHP_VERSION} was installed but $PHP_BIN is missing"

    update-alternatives --set php "$PHP_BIN" >/dev/null 2>&1 || true
    systemctl enable --now "php${PHP_VERSION}-fpm"

    "$PHP_BIN" -v | head -n 1
}

install_node() {
    say "installing Node.js ${NODE_MAJOR}"

    wait_for_apt_lock
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    apt_get install -y --no-install-recommends nodejs

    require_command node
    require_command npm
    node --version
    npm --version
}

install_docker() {
    if [ "$INSTALL_DOCKER" = "0" ]; then
        warn "Docker installation skipped (CLOUDFORGE_INSTALL_DOCKER=0)"
        return
    fi

    if command -v docker >/dev/null 2>&1; then
        say "Docker is already installed"
        timeout 120s systemctl enable --now docker 2>/dev/null || warn "Docker is installed but the service did not start"
        return
    fi

    if install_docker_ce; then
        return
    fi

    warn "Docker's official repository failed; falling back to Ubuntu's docker.io package"

    if install_distro_docker; then
        return
    fi

    warn "Docker could not be installed; CloudForge will continue without Docker inventory"
}

install_docker_ce() {
    say "installing Docker Engine from Docker's repository"

    wait_for_apt_lock

    local arch
    arch="$(dpkg --print-architecture)"

    install -m 0755 -d /etc/apt/keyrings

    if ! curl -fsSL --http1.1 --retry 3 --retry-delay 2 \
        "https://download.docker.com/linux/${OS_ID}/gpg" \
        -o /etc/apt/keyrings/docker.asc; then
        return 1
    fi

    chmod a+r /etc/apt/keyrings/docker.asc

    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/%s %s stable\n' \
        "$arch" "$OS_ID" "$OS_CODENAME" >/etc/apt/sources.list.d/docker.list

    if ! apt_get update; then
        return 1
    fi

    if ! apt_get install -y --no-install-recommends \
        containerd.io \
        docker-buildx-plugin \
        docker-ce \
        docker-ce-cli \
        docker-compose-plugin; then
        return 1
    fi

    if ! timeout 120s systemctl enable --now docker; then
        return 1
    fi

    docker --version
}

install_distro_docker() {
    say "installing Docker Engine from the distribution repository"

    rm -f /etc/apt/sources.list.d/docker.list /etc/apt/keyrings/docker.asc

    wait_for_apt_lock

    if ! apt_get update; then
        return 1
    fi

    if ! apt_get install -y --no-install-recommends docker.io; then
        return 1
    fi

    if ! timeout 120s systemctl enable --now docker; then
        return 1
    fi

    docker --version
}

install_composer() {
    if [ -x /usr/local/bin/composer ]; then
        say "Composer is already installed"
        return
    fi

    say "installing Composer"

    local expected actual installer
    installer="$(mktemp)"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    "$PHP_BIN" -r "copy('https://getcomposer.org/installer', '$installer');"
    actual="$("$PHP_BIN" -r "echo hash_file('sha384', '$installer');")"

    [ "$expected" = "$actual" ] || {
        rm -f "$installer"
        fail "Composer installer signature verification failed"
    }

    "$PHP_BIN" "$installer" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f "$installer"

    /usr/local/bin/composer --version
}

resolve_public_ip() {
    if [ -n "$PUBLIC_IP_OVERRIDE" ]; then
        PUBLIC_IP="$PUBLIC_IP_OVERRIDE"
    else
        PUBLIC_IP="$(curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)"

        if [ -z "$PUBLIC_IP" ]; then
            PUBLIC_IP="$(curl -4 -fsS --max-time 8 https://ifconfig.me 2>/dev/null || true)"
        fi

        if [ -z "$PUBLIC_IP" ]; then
            PUBLIC_IP="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+(\.[0-9]+){3}$' | head -n 1 || true)"
        fi
    fi

    APP_HOST="${DOMAIN:-$PUBLIC_IP}"

    [ -n "$APP_HOST" ] || fail "could not determine the public address; set CLOUDFORGE_PUBLIC_IP"

    say "public address: ${PUBLIC_IP:-unknown}; application host: $APP_HOST"

    if [ -n "$DOMAIN" ]; then
        local resolved
        resolved="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR == 1 { print $1 }' || true)"

        if [ -z "$resolved" ]; then
            warn "DNS for $DOMAIN did not resolve yet; certificate issuance may fail"
        elif [ -n "$PUBLIC_IP" ] && [ "$resolved" != "$PUBLIC_IP" ]; then
            warn "$DOMAIN resolves to $resolved but the detected public address is $PUBLIC_IP; certificate issuance may fail"
        fi
    fi
}

fetch_source() {
    say "fetching CloudForge source (${REPO_REF})"

    mkdir -p "$(dirname "$APP_DIR")"

    if [ -d "$APP_DIR/.git" ]; then
        git -C "$APP_DIR" fetch --depth 1 origin "$REPO_REF"
        git -C "$APP_DIR" checkout -B main FETCH_HEAD
    else
        if [ -e "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
            fail "$APP_DIR exists but is not a git checkout; move it or set CLOUDFORGE_APP_DIR"
        fi

        if [ -n "$REPO_TOKEN" ]; then
            local basic
            basic="$(printf 'x-access-token:%s' "$REPO_TOKEN" | base64 | tr -d '\n')"
            GIT_TERMINAL_PROMPT=0 git \
                -c "http.extraheader=AUTHORIZATION: basic $basic" \
                clone --depth 1 --branch "$REPO_REF" "$REPO_URL" "$APP_DIR"
            git -C "$APP_DIR" remote set-url origin "$REPO_URL"
        else
            GIT_TERMINAL_PROMPT=0 git clone --depth 1 --branch "$REPO_REF" "$REPO_URL" "$APP_DIR"
        fi
    fi

    [ -f "$APP_ROOT/artisan" ] || fail "$APP_ROOT/artisan is missing; the source checkout is incomplete"
}

env_value() {
    local key="$1" file="$2"

    [ -f "$file" ] || return 0

    sed -n "s/^${key}=//p" "$file" | tail -n 1 | sed -e 's/^"//' -e 's/"$//'
}

set_env_value() {
    local key="$1" value="$2" file="${3:-$ENV_FILE}" escaped

    escaped="$(printf '%s' "$value" | sed -e 's/[\\&|]/\\&/g')"

    if grep -q "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

prepare_env_file() {
    if [ ! -f "$ENV_FILE" ]; then
        cp "$APP_ROOT/.env.example" "$ENV_FILE"
        say "created $ENV_FILE"
    fi

    DB_PASSWORD="$(env_value DB_PASSWORD "$ENV_FILE" || true)"

    if [ -z "$DB_PASSWORD" ]; then
        DB_PASSWORD="$(openssl rand -hex 24)"
    fi

    chmod 0640 "$ENV_FILE"
}

ensure_database() {
    say "provisioning PostgreSQL"

    systemctl enable --now postgresql

    if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname = '$DB_USER'" | grep -q 1; then
        runuser -u postgres -- psql -v ON_ERROR_STOP=1 -q <<SQL
CREATE ROLE "$DB_USER" LOGIN PASSWORD '$DB_PASSWORD';
SQL
    else
        runuser -u postgres -- psql -v ON_ERROR_STOP=1 -q <<SQL
ALTER ROLE "$DB_USER" WITH LOGIN PASSWORD '$DB_PASSWORD';
SQL
    fi

    if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'" | grep -q 1; then
        runuser -u postgres -- createdb -O "$DB_USER" "$DB_NAME"
    fi
}

configure_environment() {
    say "writing application configuration"

    set_env_value APP_NAME "CloudForge"
    set_env_value APP_ENV "production"
    set_env_value APP_DEBUG "false"
    set_env_value APP_URL "http://$APP_HOST"
    set_env_value APP_TIMEZONE "UTC"
    set_env_value LOG_LEVEL "warning"

    set_env_value DB_CONNECTION "pgsql"
    set_env_value DB_HOST "127.0.0.1"
    set_env_value DB_PORT "5432"
    set_env_value DB_DATABASE "$DB_NAME"
    set_env_value DB_USERNAME "$DB_USER"
    set_env_value DB_PASSWORD "$DB_PASSWORD"

    set_env_value SESSION_DRIVER "redis"
    set_env_value SESSION_ENCRYPT "true"
    set_env_value SESSION_SECURE_COOKIE "false"
    set_env_value CACHE_STORE "redis"
    set_env_value QUEUE_CONNECTION "redis"
    set_env_value REDIS_CLIENT "phpredis"
    set_env_value REDIS_HOST "127.0.0.1"
    set_env_value REDIS_PORT "6379"

    set_env_value CLOUDFORGE_ALLOW_REGISTRATION "$OPEN_REGISTRATION"
    set_env_value CLOUDFORGE_AGENT_INSTALL_URL "http://$APP_HOST/install.sh"
    set_env_value CLOUDFORGE_CONTROL_PLANE_URL "http://$APP_HOST"
    set_env_value CLOUDFORGE_METRICS_RETENTION_DAYS "14"
}

install_php_dependencies() {
    say "installing PHP dependencies"
    (cd "$APP_ROOT" && "$PHP_BIN" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)
}

generate_app_key() {
    if [ -n "$(env_value APP_KEY "$ENV_FILE" || true)" ]; then
        return
    fi

    say "generating the application key"
    (cd "$APP_ROOT" && "$PHP_BIN" artisan key:generate --force --no-interaction)
}

build_frontend() {
    say "building dashboard assets"
    (cd "$APP_ROOT" && npm ci --no-audit --no-fund && npm run build)
}

install_php_ini() {
    local ini_dir="/etc/php/${PHP_VERSION}/fpm/conf.d"

    [ -d "$ini_dir" ] || fail "PHP-FPM configuration directory $ini_dir is missing"

    cat >"$ini_dir/99-cloudforge.ini" <<'INI'
max_execution_time = 60
memory_limit = 512M
upload_max_filesize = 8M
post_max_size = 16M
INI

    systemctl restart "php${PHP_VERSION}-fpm"

    local candidate attempts=0

    while [ "$attempts" -lt 20 ]; do
        for candidate in "/run/php/php${PHP_VERSION}-fpm.sock" /run/php/php*-fpm.sock; do
            if [ -S "$candidate" ]; then
                PHP_SOCK="$candidate"
                break 2
            fi
        done

        attempts=$((attempts + 1))
        sleep 1
    done

    [ -n "$PHP_SOCK" ] || fail "could not find the PHP-FPM socket for PHP ${PHP_VERSION}"
    say "PHP-FPM socket: $PHP_SOCK"
}

set_permissions() {
    say "setting permissions"

    chown -R www-data:www-data "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"
    chmod -R ug+rwX "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"
    chown root:www-data "$ENV_FILE"
    chmod 0640 "$ENV_FILE"
}

nginx_app_server() {
    local listen="$1" server_name="$2" ssl="$3"

    cat <<NGINX
server {
    listen ${listen};
    server_name ${server_name};
    root ${APP_ROOT}/public;
    index index.php;
    client_max_body_size 16m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
NGINX

    if [ "$ssl" = "1" ]; then
        cat <<NGINX

    ssl_certificate /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    add_header Strict-Transport-Security "max-age=31536000" always;
NGINX
    fi

    cat <<NGINX

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_read_timeout 65s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
}

configure_nginx() {
    say "configuring Nginx"
    SERVER_NAME="${DOMAIN:-_}"

    local site=/etc/nginx/sites-available/cloudforge

    {
        if [ "$TLS_ACTIVE" = "1" ]; then
            cat <<NGINX
server {
    listen 80;
    server_name ${SERVER_NAME};
    return 301 https://\$host\$request_uri;
}

NGINX
            nginx_app_server "443 ssl" "$SERVER_NAME" "1"
        else
            nginx_app_server "80" "$SERVER_NAME" "0"
        fi

        # The loopback listener is used for local agent enrollment when the
        # public endpoint is HTTP-only or certificate issuance failed.
        nginx_app_server "127.0.0.1:8080" "_" "0"
    } >"$site"

    ln -sf "$site" /etc/nginx/sites-enabled/cloudforge
    rm -f /etc/nginx/sites-enabled/default

    nginx -t
    systemctl reload nginx
}

certificate_exists() {
    [ -n "$DOMAIN" ] && [ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]
}

obtain_certificate() {
    if [ "$HTTP_ONLY" = "1" ]; then
        return 1
    fi

    if certificate_exists; then
        return 0
    fi

    say "requesting a Let's Encrypt certificate for $DOMAIN"

    if certbot certonly \
        --webroot \
        --webroot-path "$APP_ROOT/public" \
        --domains "$DOMAIN" \
        --email "$ACME_EMAIL" \
        --agree-tos \
        --no-eff-email \
        --non-interactive \
        --keep-until-expiring \
        --deploy-hook "systemctl reload nginx"; then
        return 0
    fi

    warn "certificate issuance failed; the dashboard will stay on HTTP"
    return 1
}

install_certificate_renewal_hook() {
    install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy

    cat >/etc/letsencrypt/renewal-hooks/deploy/cloudforge-nginx.sh <<'HOOK'
#!/bin/sh
systemctl reload nginx
HOOK

    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/cloudforge-nginx.sh
}

finalize_environment() {
    local url secure

    if [ "$TLS_ACTIVE" = "1" ]; then
        url="https://${DOMAIN}"
        secure="true"
    else
        url="http://${APP_HOST}"
        secure="false"
    fi

    set_env_value APP_URL "$url"
    set_env_value SESSION_SECURE_COOKIE "$secure"
    set_env_value CLOUDFORGE_CONTROL_PLANE_URL "$url"
    set_env_value CLOUDFORGE_AGENT_INSTALL_URL "$url/install.sh"

    APP_URL="$url"
}

run_migrations() {
    say "running database migrations"

    runuser -u www-data -- "$PHP_BIN" "$APP_ROOT/artisan" optimize:clear
    runuser -u www-data -- "$PHP_BIN" "$APP_ROOT/artisan" migrate --force --no-interaction
    runuser -u www-data -- "$PHP_BIN" "$APP_ROOT/artisan" storage:link >/dev/null 2>&1 || true
    runuser -u www-data -- "$PHP_BIN" "$APP_ROOT/artisan" optimize
}

install_scheduler() {
    say "installing the scheduler"

    cat >/etc/cron.d/cloudforge <<CRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www-data cd ${APP_ROOT} && ${PHP_BIN} artisan schedule:run >> /var/log/cloudforge-scheduler.log 2>&1
CRON

    chmod 0644 /etc/cron.d/cloudforge
    touch /var/log/cloudforge-scheduler.log
    chown www-data:www-data /var/log/cloudforge-scheduler.log
}

install_queue_worker() {
    say "installing the queue worker"

    cat >/etc/systemd/system/cloudforge-queue.service <<UNIT
[Unit]
Description=CloudForge queue worker
After=network-online.target postgresql.service redis-server.service
Wants=network-online.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=${APP_ROOT}
ExecStart=${PHP_BIN} artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
UNIT

    systemctl daemon-reload
    systemctl enable --now cloudforge-queue
}

install_go_toolchain() {
    local arch="$1" temp

    if [ -x "$GO_ROOT/bin/go" ]; then
        return
    fi

    say "installing the Go toolchain for the agent build"
    mkdir -p /usr/local/cloudforge
    temp="$(mktemp -d)"

    if ! curl -fsSL "https://go.dev/dl/go${GO_VERSION}.linux-${arch}.tar.gz" -o "$temp/go.tar.gz"; then
        rm -rf "$temp"
        fail "could not download the Go toolchain"
    fi

    if ! tar -C /usr/local/cloudforge -xzf "$temp/go.tar.gz"; then
        rm -rf "$temp"
        fail "could not extract the Go toolchain"
    fi

    rm -rf "$temp"

    [ -x "$GO_ROOT/bin/go" ] || fail "the Go toolchain did not install correctly"
}

build_agent() {
    if [ -n "$AGENT_BINARY_INPUT" ]; then
        AGENT_BINARY="$AGENT_BINARY_INPUT"

        [ -x "$AGENT_BINARY" ] || fail "CLOUDFORGE_AGENT_BINARY $AGENT_BINARY is not executable"
        say "using the agent binary at $AGENT_BINARY"
        return
    fi

    local arch
    arch="$(detect_arch)"
    install_go_toolchain "$arch"

    say "building the agent from source"
    AGENT_BINARY="/usr/local/cloudforge/cloudforge-agent"

    (
        cd "$APP_DIR/apps/agent"
        CGO_ENABLED=0 GOOS=linux GOARCH="$arch" "$GO_ROOT/bin/go" build \
            -trimpath \
            -ldflags "-s -w -X main.version=${CLOUDFORGE_VERSION:-1.2.2}" \
            -o "$AGENT_BINARY" \
            ./cmd/cloudforge-agent
    )

    chmod 0755 "$AGENT_BINARY"
    say "agent binary: $AGENT_BINARY"
}

bootstrap_agent() {
    local agent_url

    if [ "$SKIP_AGENT" = "1" ]; then
        warn "agent installation skipped (CLOUDFORGE_SKIP_AGENT=1)"
        return
    fi

    if [ "$TLS_ACTIVE" = "1" ]; then
        agent_url="https://${DOMAIN}"
    else
        agent_url="http://127.0.0.1:8080"
    fi

    say "installing and enrolling the local agent through $agent_url"

    CLOUDFORGE_APP_DIR="$APP_ROOT" \
    CLOUDFORGE_PHP_USER="www-data" \
    CLOUDFORGE_PHP_BIN="$PHP_BIN" \
    CLOUDFORGE_CONTROL_PLANE_URL="$agent_url" \
    CLOUDFORGE_INSTALLER="$APP_ROOT/public/install.sh" \
    CLOUDFORGE_AGENT_BINARY="$AGENT_BINARY" \
        sh "$APP_DIR/infrastructure/scripts/bootstrap-node.sh"
}

verify_installation() {
    local code

    say "verifying the dashboard"

    if [ "$TLS_ACTIVE" = "1" ]; then
        if ! curl -fsS --max-time 15 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/up" >/dev/null; then
            warn "the HTTPS health endpoint did not respond yet"
        fi

        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/register" || true)"
    else
        if ! curl -fsS --max-time 15 -H "Host: ${APP_HOST}" http://127.0.0.1/up >/dev/null; then
            warn "the HTTP health endpoint did not respond yet"
        fi

        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 -H "Host: ${APP_HOST}" http://127.0.0.1/register || true)"
    fi

    case "$code" in
        200)
            say "the first-run signup page is reachable"
            ;;
        403)
            warn "registration is closed because an account already exists; sign in instead"
            ;;
        *)
            warn "the signup page returned HTTP ${code:-unknown}"
            ;;
    esac
}

print_summary() {
    local agent_status

    agent_status="$(systemctl is-active cloudforge-agent 2>/dev/null || true)"
    [ -n "$agent_status" ] || agent_status="not installed"

    cat <<EOF

CloudForge is ready.

  Dashboard:        ${APP_URL}
  First account:    ${APP_URL}/register
  Control plane:    ${APP_ROOT}
  Agent:            ${agent_status}
  Install log:      ${LOG_FILE}

EOF

    if [ "$TLS_ACTIVE" != "1" ]; then
        cat <<EOF
  WARNING: HTTPS is not active. Do not expose this installation to the public
  internet with real accounts until you configure a domain and certificate.

EOF
    fi

    if [ "$OPEN_REGISTRATION" != "1" ]; then
        cat <<EOF
  Registration closes after the first account. Invite teammates from Members.

EOF
    fi

    cat <<EOF
Next:
  1. Open ${APP_URL}/register and create the owner account.
  2. Create the organization. The local bootstrap server is claimed automatically.
  3. Add projects and environments, then invite your team.

Make sure inbound TCP 80 and 443 are open in the VPS firewall.

Phase 1 manages organizations, projects, environments, servers, members, API
tokens and audit history. Application deployment and database provisioning are
later milestones.

Re-run this installer at any time to update the application and agent.
EOF
}

main() {
    require_root
    trap on_error ERR
    setup_logging

    say "starting CloudForge installation"
    load_os

    validate_config
    install_base_packages
    install_php
    install_node
    install_docker
    install_composer
    resolve_public_ip

    fetch_source
    prepare_env_file
    ensure_database
    configure_environment

    install_php_dependencies
    generate_app_key
    build_frontend

    install_php_ini
    set_permissions

    if [ "$HTTP_ONLY" != "1" ] && certificate_exists; then
        TLS_ACTIVE=1
    fi

    configure_nginx

    if [ "$TLS_ACTIVE" != "1" ] && [ "$HTTP_ONLY" != "1" ]; then
        if obtain_certificate; then
            TLS_ACTIVE=1
            configure_nginx
        fi
    fi

    install_certificate_renewal_hook
    finalize_environment
    chown root:www-data "$ENV_FILE"
    chmod 0640 "$ENV_FILE"
    run_migrations
    install_scheduler
    install_queue_worker

    if [ "$SKIP_AGENT" != "1" ]; then
        build_agent
        bootstrap_agent
    fi

    verify_installation
    print_summary
}

if [ "${CLOUDFORGE_INSTALLER_TEST_MODE:-0}" = "1" ]; then
    # Sourced by tests/shell/one_command_installer_test.sh.
    return 0 2>/dev/null || exit 0
fi

main "$@"
