#!/usr/bin/env bash
# SRS Radio — deploy / update script
# Usage:
#   ./deploy.sh          — full install (first run on a fresh server)
#   ./deploy.sh update   — pull latest code and recompile only

set -euo pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

ok()      { echo -e "  ${GREEN}✔${RESET}  $1"; }
fail()    { echo -e "  ${RED}✘${RESET}  $1" >&2; exit 1; }
warn()    { echo -e "  ${YELLOW}⚠${RESET}  $1"; }
info()    { echo -e "  ${CYAN}·${RESET}  $1"; }
section() { echo -e "\n${BOLD}━━ $1 ━━${RESET}"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODE="${1:-install}"

# ── Root check ────────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    fail "Run this script as root (sudo ./deploy.sh)"
fi

# ─────────────────────────────────────────────────────────────────────────────
# UPDATE MODE — just pull + recompile, skip system-level steps
# ─────────────────────────────────────────────────────────────────────────────
if [[ "$MODE" == "update" ]]; then
    section "Update: pull + recompile"

    cd "$SCRIPT_DIR"
    git pull
    ok "git pull"

    composer install --no-dev --optimize-autoloader --no-interaction
    ok "composer install"

    php bin/console cache:clear --env=prod --no-warmup
    php bin/console cache:warmup --env=prod
    ok "Symfony cache refreshed"

    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    ok "Migrations applied"

    chown -R www-data:www-data "$SCRIPT_DIR/var" "$SCRIPT_DIR/public/sounds"
    ok "Permissions fixed"

    systemctl reload php8.4-fpm
    systemctl reload nginx
    ok "Services reloaded"

    echo ""
    echo -e "${GREEN}${BOLD}Update complete.${RESET}"
    exit 0
fi

# ─────────────────────────────────────────────────────────────────────────────
# FULL INSTALL MODE
# ─────────────────────────────────────────────────────────────────────────────

# ── System packages ───────────────────────────────────────────────────────────
section "System packages"

apt-get update -qq
apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg lsb-release software-properties-common \
    nginx \
    mysql-client \
    ffmpeg \
    python3 python3-pip python3-venv \
    unzip git >/dev/null

ok "Base packages installed"

# ── PHP 8.4 ───────────────────────────────────────────────────────────────────
section "PHP 8.4"

if ! php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 4 ? 0 : 1);' 2>/dev/null; then
    info "Adding ondrej/php PPA…"
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi

apt-get install -y --no-install-recommends \
    php8.4-cli php8.4-fpm php8.4-mysql \
    php8.4-curl php8.4-mbstring php8.4-xml php8.4-intl \
    php8.4-ctype php8.4-iconv php8.4-json \
    php8.4-pcntl php8.4-posix >/dev/null

ok "PHP $(php -r 'echo PHP_VERSION;') with required extensions"

# ── Composer ──────────────────────────────────────────────────────────────────
section "Composer"

if ! command -v composer &>/dev/null; then
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    [[ "$EXPECTED_CHECKSUM" == "$ACTUAL_CHECKSUM" ]] || fail "Composer installer checksum mismatch"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm composer-setup.php
    ok "Composer installed"
else
    ok "Composer $(composer --version --no-ansi 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1) already present"
fi

# ── Python TTS tools ──────────────────────────────────────────────────────────
section "Python TTS tools"

if ! command -v edge-tts &>/dev/null; then
    pip3 install --quiet edge-tts
    ok "edge-tts installed"
else
    ok "edge-tts already installed"
fi

# ── MySQL ─────────────────────────────────────────────────────────────────────
section "MySQL"

if ! command -v mysqld &>/dev/null && ! systemctl is-active --quiet mysql 2>/dev/null; then
    apt-get install -y --no-install-recommends mysql-server >/dev/null
    systemctl enable mysql --quiet
    systemctl start mysql
    ok "MySQL installed and started"
else
    ok "MySQL already present"
fi

# ── Create database and user from DATABASE_URL ────────────────────────────────
section "Database setup"

DB_URL=""
for ENV_FILE in "$SCRIPT_DIR/.env.local" "$SCRIPT_DIR/.env"; do
    [[ -f "$ENV_FILE" ]] && V=$(grep -E '^DATABASE_URL=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' || true)
    [[ -n "${V:-}" ]] && DB_URL="$V" && break
done

if [[ -z "$DB_URL" || "$DB_URL" == *"!ChangeMe!"* ]]; then
    warn "DATABASE_URL not configured or still placeholder — skipping DB creation."
    warn "Set it in .env.local first, then re-run: ./deploy.sh update"
else
    # Parse mysql://user:pass@host:port/dbname
    DB_USER=$(echo "$DB_URL" | grep -oP '(?<=mysql://)[^:]+')
    DB_PASS=$(echo "$DB_URL" | grep -oP '(?<=mysql://[^:]{1,64}:)[^@]+')
    DB_HOST=$(echo "$DB_URL" | grep -oP '(?<=@)[^:/]+')
    DB_PORT=$(echo "$DB_URL" | grep -oP '(?<=@[^:]{1,64}:)\d+' || echo "3306")
    DB_NAME=$(echo "$DB_URL" | grep -oP '(?<=/)[^?]+')

    if mysql -u root -e "SELECT 1" &>/dev/null 2>&1; then
        mysql -u root <<SQL 2>/dev/null || true
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
FLUSH PRIVILEGES;
SQL
        ok "Database '${DB_NAME}' and user '${DB_USER}' ready"
    else
        warn "Cannot connect to MySQL as root without password — create DB manually."
    fi
fi

# ── Project dependencies ──────────────────────────────────────────────────────
section "Composer dependencies"

cd "$SCRIPT_DIR"
composer install --no-dev --optimize-autoloader --no-interaction
ok "Vendor dependencies installed"

# ── .env.local ────────────────────────────────────────────────────────────────
section ".env.local"

if [[ ! -f "$SCRIPT_DIR/.env.local" ]]; then
    warn ".env.local does not exist — creating a template. Fill in all values before starting the radio."
    cat > "$SCRIPT_DIR/.env.local" <<'ENVTEMPLATE'
APP_ENV=prod
APP_SECRET=

DATABASE_URL="mysql://srs_radio:changeme@127.0.0.1:3306/srs_radio?serverVersion=8.0.32&charset=utf8mb4"

SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
SPOTIFY_REDIRECT_URI=https://your-host/spotify/callback

SONOS_IP=
SONOS_CLIENT_ID=
SONOS_CLIENT_SECRET=
SONOS_REDIRECT_URI=https://your-host/sonos/callback

GROQ_API_KEY=

DJ_SERVER_URL=http://your-server-ip:8080
DJ_LANGUAGE=nl
DJ_TTS_PROVIDER=edge
DJ_VOICE=nl-NL-MaartenNeural
DJ_BED_FILE=public/sounds/fillers/dj-bed.mp3
DJ_BED_VOLUME=0.20

JIRA_HOST=https://your-company.atlassian.net
JIRA_USER=your-email@srs.nl
JIRA_TOKEN=

WEATHER_API_KEY=
WEATHER_LOCATION=Amsterdam,NL
WEATHER_HOUR=12
WEATHER_MINUTE=20

NEWS_FEED_URL=https://www.nu.nl/rss
ENVTEMPLATE
    info "Edit $SCRIPT_DIR/.env.local and then run: ./deploy.sh update"
else
    ok ".env.local already exists"
fi

# ── Symfony cache ─────────────────────────────────────────────────────────────
section "Symfony cache"

APP_ENV_VAL=$(grep -E '^APP_ENV=' "$SCRIPT_DIR/.env.local" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || echo "prod")
php bin/console cache:clear --env="${APP_ENV_VAL}" --no-warmup
php bin/console cache:warmup --env="${APP_ENV_VAL}" 2>/dev/null || warn "Cache warmup skipped (DB not yet configured?)"
ok "Cache cleared and warmed"

# ── Database migrations ───────────────────────────────────────────────────────
section "Database migrations"

if php bin/console dbal:run-sql "SELECT 1" &>/dev/null 2>&1; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    ok "Migrations applied"
else
    warn "Database not reachable — skipping migrations. Run manually: php bin/console doctrine:migrations:migrate"
fi

# ── Directory permissions ─────────────────────────────────────────────────────
section "File permissions"

for DIR in var public/sounds public/sounds/fillers public/sounds/dj public/images/colleagues; do
    mkdir -p "$SCRIPT_DIR/$DIR"
done

chown -R www-data:www-data \
    "$SCRIPT_DIR/var" \
    "$SCRIPT_DIR/public/sounds" \
    "$SCRIPT_DIR/public/images/colleagues"

chmod -R 775 \
    "$SCRIPT_DIR/var" \
    "$SCRIPT_DIR/public/sounds" \
    "$SCRIPT_DIR/public/images/colleagues"

ok "Writable directories created and owned by www-data"

# ── Nginx ─────────────────────────────────────────────────────────────────────
section "Nginx"

NGINX_CONF="/etc/nginx/sites-available/srs-radio"
NGINX_ENABLED="/etc/nginx/sites-enabled/srs-radio"

cp "$SCRIPT_DIR/nginx-srs-radio.conf" "$NGINX_CONF"

if [[ ! -L "$NGINX_ENABLED" ]]; then
    ln -s "$NGINX_CONF" "$NGINX_ENABLED"
fi

# Disable default site if still present
[[ -L /etc/nginx/sites-enabled/default ]] && rm /etc/nginx/sites-enabled/default && info "Removed default nginx site"

# Self-signed cert for dev if production certs don't exist
CERT_FILE="/etc/ssl/certs/srs-radio-dev.pem"
KEY_FILE="/etc/ssl/private/srs-radio-dev-key.pem"

if [[ ! -f "$CERT_FILE" || ! -f "$KEY_FILE" ]]; then
    warn "SSL certificate not found — generating self-signed cert for development."
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$KEY_FILE" \
        -out "$CERT_FILE" \
        -subj "/CN=srs-radio-dev" 2>/dev/null
    ok "Self-signed certificate generated (replace with a real cert for production)"
else
    ok "SSL certificate already present"
fi

nginx -t && systemctl enable nginx --quiet && systemctl reload nginx
ok "Nginx configured and reloaded"

# ── PHP-FPM ───────────────────────────────────────────────────────────────────
section "PHP-FPM"

systemctl enable php8.4-fpm --quiet
systemctl restart php8.4-fpm
ok "PHP 8.4 FPM enabled and running"

# ── Logrotate ─────────────────────────────────────────────────────────────────
section "Log rotation"

cat > /etc/logrotate.d/srs-radio <<'LOGROTATE'
/var/www/srs-radio/var/log/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    create 0664 www-data www-data
    sharedscripts
    postrotate
        systemctl reload php8.4-fpm > /dev/null 2>&1 || true
    endscript
}
LOGROTATE
ok "Log rotation configured"

# ── Final check ───────────────────────────────────────────────────────────────
section "Pre-flight check"

echo ""
bash "$SCRIPT_DIR/check-setup.sh" || true

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo "────────────────────────────────────────────"
echo -e "${GREEN}${BOLD}Deploy complete.${RESET}"
echo ""
echo -e "Next steps:"
echo -e "  1. Edit ${CYAN}.env.local${RESET} with your API credentials"
echo -e "  2. Run ${CYAN}php bin/console doctrine:migrations:migrate${RESET} once DB is configured"
echo -e "  3. Visit ${CYAN}https://your-host/spotify/connect${RESET} to authenticate Spotify"
echo -e "  4. Visit ${CYAN}https://your-host/sonos/connect${RESET} to authenticate Sonos"
echo -e "  5. Start the radio: ${CYAN}php bin/console radio:start${RESET}"
echo ""
