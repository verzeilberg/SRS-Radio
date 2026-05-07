#!/usr/bin/env bash
# SRS Radio — pre-flight setup check
# Run this on a fresh server to see what's missing before starting the radio.

set -euo pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

ERRORS=0
WARNINGS=0

ok()   { echo -e "  ${GREEN}✔${RESET}  $1"; }
fail() { echo -e "  ${RED}✘${RESET}  $1"; ERRORS=$((ERRORS + 1)); }
warn() { echo -e "  ${YELLOW}⚠${RESET}  $1"; WARNINGS=$((WARNINGS + 1)); }
info() { echo -e "  ${CYAN}·${RESET}  $1"; }
section() { echo -e "\n${BOLD}$1${RESET}"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── PHP ──────────────────────────────────────────────────────────────────────
section "PHP"

if command -v php &>/dev/null; then
    PHP_VER=$(php -r 'echo PHP_VERSION;')
    MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
    MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
    if [[ $MAJOR -gt 8 ]] || [[ $MAJOR -eq 8 && $MINOR -ge 4 ]]; then
        ok "PHP $PHP_VER"
    else
        fail "PHP $PHP_VER found — need >= 8.4"
    fi
else
    fail "PHP not found (install php8.4-cli)"
fi

for EXT in ctype iconv curl json pdo pdo_mysql pcntl posix mbstring xml intl openssl; do
    if php -r "exit(extension_loaded('$EXT') ? 0 : 1);" 2>/dev/null; then
        ok "ext-$EXT"
    else
        fail "ext-$EXT missing — install php8.4-$EXT (or php-$EXT)"
    fi
done

# ── Composer ─────────────────────────────────────────────────────────────────
section "Composer"

if command -v composer &>/dev/null; then
    ok "composer $(composer --version --no-ansi 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)"
else
    fail "composer not found — see https://getcomposer.org/download/"
fi

if [[ -d "$SCRIPT_DIR/vendor" ]]; then
    ok "vendor/ directory exists"
else
    fail "vendor/ missing — run: composer install"
fi

# ── External programs ─────────────────────────────────────────────────────────
section "External programs"

if command -v ffmpeg &>/dev/null; then
    ok "ffmpeg $(ffmpeg -version 2>&1 | grep -oP 'ffmpeg version \K[^ ]+')"
else
    fail "ffmpeg not found — install: apt install ffmpeg"
fi

if command -v ffprobe &>/dev/null; then
    ok "ffprobe (bundled with ffmpeg)"
else
    fail "ffprobe not found (usually comes with ffmpeg)"
fi

if command -v python3 &>/dev/null; then
    ok "python3 $(python3 --version 2>&1 | grep -oP '\d+\.\d+\.\d+')"
else
    fail "python3 not found — install: apt install python3"
fi

if command -v pip3 &>/dev/null || command -v pip &>/dev/null; then
    ok "pip available"
else
    warn "pip not found — needed to install piper-tts / edge-tts (apt install python3-pip)"
fi

# Detect which TTS engine is configured
PIPER_MODEL=""
for ENV_FILE in "$SCRIPT_DIR/.env.local" "$SCRIPT_DIR/.env"; do
    if [[ -f "$ENV_FILE" ]]; then
        V=$(grep -E '^DJ_PIPER_MODEL=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' || true)
        [[ -n "$V" ]] && PIPER_MODEL="$V" && break
    fi
done

if [[ -n "$PIPER_MODEL" ]]; then
    if command -v piper &>/dev/null; then
        ok "piper TTS (active engine)"
    else
        fail "piper not found but DJ_PIPER_MODEL is set — install: pip3 install piper-tts"
    fi

    PIPER_MODEL_ABS="$SCRIPT_DIR/$PIPER_MODEL"
    if [[ -f "$PIPER_MODEL_ABS" ]]; then
        ok "Piper model found: $PIPER_MODEL ($(du -h "$PIPER_MODEL_ABS" | cut -f1))"
    else
        fail "Piper model not found at $PIPER_MODEL_ABS — download it first"
    fi

    PIPER_JSON_ABS="${PIPER_MODEL_ABS}.json"
    if [[ -f "$PIPER_JSON_ABS" ]]; then
        ok "Piper model config found: ${PIPER_MODEL}.json"
    else
        fail "Piper model config not found at ${PIPER_MODEL_ABS}.json — download it alongside the .onnx file"
    fi
else
    info "DJ_PIPER_MODEL not set — using edge-tts"
    if command -v edge-tts &>/dev/null; then
        ok "edge-tts $(edge-tts --version 2>/dev/null || echo '(installed)')"
    elif python3 -c "import edge_tts" 2>/dev/null; then
        ok "edge-tts Python module (no CLI wrapper, may need PATH fix)"
    else
        fail "edge-tts not found — install: pip3 install edge-tts"
    fi
fi

# ── Database ──────────────────────────────────────────────────────────────────
section "Database"

if command -v mysql &>/dev/null; then
    ok "mysql client available"
else
    warn "mysql client not found — install: apt install mysql-client"
fi

# Load DATABASE_URL from .env.local or .env
DB_URL=""
for ENV_FILE in "$SCRIPT_DIR/.env.local" "$SCRIPT_DIR/.env"; do
    if [[ -f "$ENV_FILE" ]]; then
        DB_URL=$(grep -E '^DATABASE_URL=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' || true)
        [[ -n "$DB_URL" ]] && break
    fi
done

if [[ -n "$DB_URL" ]]; then
    info "DATABASE_URL is set"
    # Try to connect using Symfony console
    if [[ -f "$SCRIPT_DIR/bin/console" ]] && [[ -d "$SCRIPT_DIR/vendor" ]]; then
        if php "$SCRIPT_DIR/bin/console" dbal:run-sql "SELECT 1" &>/dev/null 2>&1; then
            ok "Database connection OK"
        else
            fail "Cannot connect to database — check DATABASE_URL and that MySQL is running"
        fi
    fi
else
    fail "DATABASE_URL not set in .env or .env.local"
fi

# ── Web server ────────────────────────────────────────────────────────────────
section "Web server"

if command -v nginx &>/dev/null; then
    ok "nginx $(nginx -v 2>&1 | grep -oP '\d+\.\d+\.\d+')"
elif command -v apache2 &>/dev/null || command -v httpd &>/dev/null; then
    ok "Apache found"
else
    warn "nginx/apache not found — needed to serve the web UI"
fi

if command -v php-fpm8.4 &>/dev/null || command -v php-fpm &>/dev/null || \
   systemctl is-active --quiet php8.4-fpm 2>/dev/null || \
   systemctl is-active --quiet php-fpm 2>/dev/null; then
    ok "PHP-FPM running"
else
    warn "PHP-FPM status unclear — make sure php8.4-fpm is installed and running"
fi

# ── File permissions ──────────────────────────────────────────────────────────
section "File permissions"

for DIR in "var" "public/sounds" "public/sounds/fillers" "public/sounds/dj"; do
    FULL="$SCRIPT_DIR/$DIR"
    if [[ -d "$FULL" ]]; then
        if [[ -w "$FULL" ]]; then
            ok "$DIR/ is writable"
        else
            fail "$DIR/ is not writable (fix: chown www-data:www-data $FULL)"
        fi
    else
        if mkdir -p "$FULL" 2>/dev/null; then
            ok "$DIR/ created"
        else
            fail "$DIR/ does not exist and could not be created"
        fi
    fi
done

# ── Environment variables ─────────────────────────────────────────────────────
section "Environment variables"

check_env() {
    local KEY="$1"
    local DESC="$2"
    local OPTIONAL="${3:-}"
    local VAL=""

    for ENV_FILE in "$SCRIPT_DIR/.env.local" "$SCRIPT_DIR/.env"; do
        if [[ -f "$ENV_FILE" ]]; then
            V=$(grep -E "^${KEY}=" "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' || true)
            [[ -n "$V" ]] && VAL="$V" && break
        fi
    done

    if [[ -z "$VAL" || "$VAL" == "your-"* || "$VAL" == "your_"* || "$VAL" == "!ChangeMe!"* ]]; then
        if [[ "$OPTIONAL" == "optional" ]]; then
            warn "$KEY not set ($DESC)"
        else
            fail "$KEY not set or still placeholder ($DESC)"
        fi
    else
        ok "$KEY is set"
    fi
}

check_env "APP_SECRET"            "Symfony app secret — run: php -r \"echo bin2hex(random_bytes(16));\""
check_env "SPOTIFY_CLIENT_ID"     "Spotify developer app client ID"
check_env "SPOTIFY_CLIENT_SECRET" "Spotify developer app client secret"
check_env "SPOTIFY_REDIRECT_URI"  "Spotify OAuth redirect URI"
check_env "SONOS_IP"              "Local IP of the Sonos speaker"
check_env "SONOS_CLIENT_ID"       "Sonos developer app client ID"
check_env "SONOS_CLIENT_SECRET"   "Sonos developer app client secret"
check_env "SONOS_REDIRECT_URI"    "Sonos OAuth redirect URI"
check_env "GROQ_API_KEY"          "Groq API key (for DJ script generation)"
check_env "DJ_SERVER_URL"         "HTTP URL the Sonos speaker can reach to download DJ clips"
check_env "DJ_LANGUAGE"           "DJ announcement language: en or nl"
check_env "DATABASE_URL"          "MySQL connection string"
check_env "WEATHER_API_KEY"       "OpenWeatherMap API key" optional
check_env "JIRA_HOST"             "Jira host (e.g. company.atlassian.net)" optional
check_env "JIRA_USER"             "Jira user e-mail address" optional
check_env "JIRA_TOKEN"            "Jira API token" optional

# ── DJ bed file ───────────────────────────────────────────────────────────────
section "DJ audio bed"

BED_FILE=""
for ENV_FILE in "$SCRIPT_DIR/.env.local" "$SCRIPT_DIR/.env"; do
    if [[ -f "$ENV_FILE" ]]; then
        V=$(grep -E '^DJ_BED_FILE=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' || true)
        [[ -n "$V" ]] && BED_FILE="$V" && break
    fi
done

if [[ -z "$BED_FILE" ]]; then
    warn "DJ_BED_FILE not set — DJ clips will have no background music"
elif [[ -f "$SCRIPT_DIR/$BED_FILE" ]]; then
    ok "DJ bed file found: $BED_FILE"
else
    fail "DJ_BED_FILE=$BED_FILE but file not found at $SCRIPT_DIR/$BED_FILE"
fi

# ── Network reachability ──────────────────────────────────────────────────────
section "Network"

check_host() {
    local HOST="$1"
    local DESC="$2"
    if curl -sf --max-time 5 "https://$HOST" -o /dev/null 2>/dev/null || \
       curl -sf --max-time 5 "http://$HOST" -o /dev/null 2>/dev/null; then
        ok "$DESC ($HOST) reachable"
    else
        warn "$DESC ($HOST) not reachable — check firewall / internet"
    fi
}

check_host "api.spotify.com"   "Spotify API"
check_host "api.groq.com"      "Groq API"

SONOS_IP=""
for ENV_FILE in "$SCRIPT_DIR/.env.local" "$SCRIPT_DIR/.env"; do
    if [[ -f "$ENV_FILE" ]]; then
        V=$(grep -E '^SONOS_IP=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' || true)
        [[ -n "$V" ]] && SONOS_IP="$V" && break
    fi
done

if [[ -n "$SONOS_IP" ]]; then
    if curl -sf --max-time 5 "http://$SONOS_IP:1400/xml/device_description.xml" -o /dev/null 2>/dev/null; then
        ok "Sonos speaker ($SONOS_IP:1400) reachable"
    else
        warn "Sonos speaker ($SONOS_IP:1400) not reachable — check IP and LAN"
    fi
else
    warn "SONOS_IP not set — cannot check speaker reachability"
fi

# ── Symfony ───────────────────────────────────────────────────────────────────
section "Symfony"

if [[ -f "$SCRIPT_DIR/bin/console" ]] && [[ -d "$SCRIPT_DIR/vendor" ]]; then
    if php "$SCRIPT_DIR/bin/console" about --no-ansi 2>/dev/null | grep -q 'Symfony'; then
        SF_VER=$(php "$SCRIPT_DIR/bin/console" about --no-ansi 2>/dev/null | grep 'Version' | head -1 | awk '{print $NF}')
        ok "Symfony kernel boots OK (version $SF_VER)"
    else
        fail "Symfony kernel failed to boot — check bin/console about"
    fi

    # Check migrations
    PENDING=$(php "$SCRIPT_DIR/bin/console" doctrine:migrations:status --no-ansi 2>/dev/null | grep 'New Migrations' | grep -v ': 0' || true)
    if [[ -n "$PENDING" ]]; then
        warn "Pending database migrations — run: php bin/console doctrine:migrations:migrate"
    else
        ok "Database migrations up to date"
    fi
else
    warn "Cannot check Symfony — vendor/ missing or bin/console not found"
fi

# ── Spotify token ─────────────────────────────────────────────────────────────
section "Spotify OAuth token"

if [[ -f "$SCRIPT_DIR/bin/console" ]] && [[ -d "$SCRIPT_DIR/vendor" ]]; then
    TOKEN=$(php "$SCRIPT_DIR/bin/console" dbal:run-sql \
        "SELECT access_token FROM spotify_token LIMIT 1" --no-ansi 2>/dev/null \
        | grep -v '^$\|access_token\|rows' | head -1 | tr -d ' ' || true)

    if [[ -n "$TOKEN" && ${#TOKEN} -gt 20 ]]; then
        ok "Spotify token found in database"
        HTTP=$(curl -s -o /dev/null -w "%{http_code}" \
            -H "Authorization: Bearer $TOKEN" \
            "https://api.spotify.com/v1/me" 2>/dev/null || true)
        if [[ "$HTTP" == "200" ]]; then
            ok "Spotify token is valid (HTTP 200)"
        elif [[ "$HTTP" == "401" ]]; then
            warn "Spotify token expired — visit /spotify/connect to re-authenticate"
        else
            warn "Spotify token check returned HTTP $HTTP"
        fi
    else
        fail "No Spotify token in database — visit /spotify/connect to authenticate"
    fi
else
    warn "Cannot check Spotify token — database not available"
fi

# ── Sonos token ───────────────────────────────────────────────────────────────
section "Sonos OAuth token"

if [[ -f "$SCRIPT_DIR/bin/console" ]] && [[ -d "$SCRIPT_DIR/vendor" ]]; then
    SONOS_TOKEN=$(php "$SCRIPT_DIR/bin/console" dbal:run-sql \
        "SELECT access_token FROM sonos_token LIMIT 1" --no-ansi 2>/dev/null \
        | grep -v '^$\|access_token\|rows' | head -1 | tr -d ' ' || true)

    if [[ -n "$SONOS_TOKEN" && ${#SONOS_TOKEN} -gt 20 ]]; then
        ok "Sonos token found in database"
    else
        warn "No Sonos token in database — visit /sonos/connect to authenticate"
    fi
else
    warn "Cannot check Sonos token — database not available"
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "────────────────────────────────────────────"
if [[ $ERRORS -eq 0 && $WARNINGS -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All checks passed. Ready to go!${RESET}"
elif [[ $ERRORS -eq 0 ]]; then
    echo -e "${YELLOW}${BOLD}$WARNINGS warning(s) — radio may work but review the above.${RESET}"
else
    echo -e "${RED}${BOLD}$ERRORS error(s), $WARNINGS warning(s) — fix the red items before starting.${RESET}"
fi
echo ""

[[ $ERRORS -eq 0 ]]
