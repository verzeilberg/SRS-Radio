#!/usr/bin/env bash
# SRS Radio — fresh install script
# Run this on a clean Debian/Ubuntu server to set up everything the radio needs.
# Usage:
#   sudo ./install.sh
#   # then edit .env.local with your API keys
#   # then visit https://your-host/spotify/connect and /sonos/connect

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

if [[ $EUID -ne 0 ]]; then
    fail "Run this script as root (sudo ./install.sh)"
fi

# ── System packages ───────────────────────────────────────────────────────────
section "System packages"

apt-get update -qq
apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg lsb-release software-properties-common \
    nginx \
    mysql-server \
    ffmpeg \
    python3 python3-pip python3-venv \
    unzip git >/dev/null
ok "Base packages (nginx, mysql-server, ffmpeg, python3, git, ...)"

# ── PHP 8.4 ───────────────────────────────────────────────────────────────────
section "PHP 8.4"

if ! php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 4 ? 0 : 1);' 2>/dev/null; then
    info "Adding ondrej/php PPA for PHP 8.4..."
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi

apt-get install -y --no-install-recommends \
    php8.4-cli php8.4-fpm php8.4-mysql \
    php8.4-curl php8.4-mbstring php8.4-xml php8.4-intl \
    php8.4-ctype php8.4-iconv php8.4-pcntl php8.4-posix >/dev/null
ok "PHP 8.4 with all required extensions"

# ── Composer ──────────────────────────────────────────────────────────────────
section "Composer"

if ! command -v composer &>/dev/null; then
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
        rm composer-setup.php
        fail "Composer installer checksum mismatch"
    fi
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm composer-setup.php
    ok "Composer installed"
else
    ok "Composer $(composer --version --no-ansi 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)"
fi

# ── Python TTS (edge-tts) ─────────────────────────────────────────────────────
section "Python TTS tools"

pip3 install --quiet edge-tts
ok "edge-tts installed"

# ── MySQL ─────────────────────────────────────────────────────────────────────
section "MySQL"

systemctl enable mysql --quiet
systemctl start mysql
ok "MySQL started"

# ── Project dependencies ──────────────────────────────────────────────────────
section "Composer dependencies"

cd "$SCRIPT_DIR"
composer install --no-dev --optimize-autoloader --no-interaction
ok "Vendor dependencies installed"

# ── .env.local ────────────────────────────────────────────────────────────────
section ".env.local"

if [[ ! -f "$SCRIPT_DIR/.env.local" ]]; then
    warn "Creating template .env.local — fill in your API keys before starting!"
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
    info "Edit $SCRIPT_DIR/.env.local with your secrets"
else
    ok ".env.local already exists"
fi

# ── Database ──────────────────────────────────────────────────────────────────
section "Database setup"

# Parse DATABASE_URL
DB_URL=$(grep -E '^DATABASE_URL=' "$SCRIPT_DIR/.env.local" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || echo "")
if [[ -z "$DB_URL" || "$DB_URL" == *"!ChangeMe!"* || "$DB_URL" == *"changeme"* ]]; then
    warn "DATABASE_URL not configured — create the database manually:"
    warn "  mysql -u root -e \"CREATE DATABASE srs_radio;\""
else
    DB_USER=$(echo "$DB_URL" | grep -oP '(?<=mysql://)[^:]+')
    DB_PASS=$(echo "$DB_URL" | grep -oP '(?<=mysql://[^:]{1,64}:)[^@]+')
    DB_HOST=$(echo "$DB_URL" | grep -oP '(?<=@)[^:/]+')
    DB_NAME=$(echo "$DB_URL" | grep -oP '(?<=/)[^?]+')

    mysql -u root <<SQL 2>/dev/null || warn "DB creation failed — may need manual setup"
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
FLUSH PRIVILEGES;
SQL
    ok "Database '$DB_NAME' and user '$DB_USER' ready"
fi

# ── Migrations ────────────────────────────────────────────────────────────────
section "Database migrations"

if php "$SCRIPT_DIR/bin/console" dbal:run-sql "SELECT 1" &>/dev/null 2>&1; then
    php "$SCRIPT_DIR/bin/console" doctrine:migrations:migrate --no-interaction --allow-no-migration
    ok "Migrations applied"
else
    warn "Database not reachable — run migrations later: php bin/console doctrine:migrations:migrate"
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

ok "Writable directories created"

# ── Nginx ─────────────────────────────────────────────────────────────────────
section "Nginx"

NGINX_CONF="/etc/nginx/sites-available/srs-radio"

cp "$SCRIPT_DIR/nginx-srs-radio.conf" "$NGINX_CONF"

if [[ ! -L /etc/nginx/sites-enabled/srs-radio ]]; then
    ln -s "$NGINX_CONF" /etc/nginx/sites-enabled/srs-radio
fi

[[ -L /etc/nginx/sites-enabled/default ]] && rm /etc/nginx/sites-enabled/default && info "Removed default nginx site"

# Self-signed cert for dev
CERT="/etc/ssl/certs/srs-radio-dev.pem"
KEY="/etc/ssl/private/srs-radio-dev-key.pem"
if [[ ! -f "$CERT" || ! -f "$KEY" ]]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$KEY" -out "$CERT" \
        -subj "/CN=srs-radio-dev" 2>/dev/null
    ok "Self-signed SSL cert generated"
else
    ok "SSL cert already present"
fi

nginx -t && systemctl enable nginx --quiet && systemctl reload nginx
ok "Nginx configured"

# ── PHP-FPM ───────────────────────────────────────────────────────────────────
section "PHP-FPM"

systemctl enable php8.4-fpm --quiet
systemctl restart php8.4-fpm
ok "PHP 8.4 FPM running"

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

# ── Done ──────────────────────────────────────────────────────────────────────
section "Done"

echo ""
echo -e "${GREEN}${BOLD}Installation complete.${RESET}"
echo ""
echo "Next steps:"
echo -e "  1. Edit ${CYAN}.env.local${RESET} with your API credentials"
echo -e "  2. Run ${CYAN}php bin/console doctrine:migrations:migrate${RESET} if DB wasn't reachable"
echo -e "  3. Visit ${CYAN}https://your-host/spotify/connect${RESET} to authenticate Spotify"
echo -e "  4. Visit ${CYAN}https://your-host/sonos/connect${RESET} to authenticate Sonos"
echo -e "  5. Start the radio: ${CYAN}php bin/console radio:start${RESET}"
echo ""

# Run the pre-flight check
bash "$SCRIPT_DIR/check-setup.sh" || true
