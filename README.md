# SRS Radio

An autonomous AI-powered radio station for the office. SRS Radio plays music from Spotify through a Sonos speaker, inserts AI-generated DJ announcements between tracks, monitors Jira for critical tickets, announces the weather and news, and celebrates colleague birthdays — all while displaying a live dashboard in the browser.

---

## Features

- **Autonomous playback** — continuously picks and queues tracks from Spotify playlists or top-track pools with duplicate avoidance and seamless transitions
- **AI DJ** — generates natural radio-style announcements using Groq (LLaMA 3.3-70B), voiced via Edge TTS, ElevenLabs, or Piper, mixed with optional background bed music
- **Scheduled segments** — time-triggered announcements: morning (9:00), lunch (12:00), afternoon, Friday wind-down, end of day, weather report, and news headlines
- **Birthday announcements** — at 11:00 the station reads out a personalised birthday message, plays a dedicated birthday song, and shows a confetti overlay on the dashboard
- **Jira alarm** — a parallel monitor polls Jira for high-priority tickets and triggers an air-raid siren clip at the next song boundary
- **Live web dashboard** — real-time now-playing display with EQ visualiser, progress bar, next track, DJ text, album art, and a Jira alert panel
- **Colleague management** — a small web UI to manage colleagues and their birthdays (with photo upload)

---

## Tech stack

| Layer | Technology |
|---|---|
| Framework | Symfony 8 / PHP 8.4 |
| Database | MySQL 8 / MariaDB via Doctrine ORM |
| Music | Spotify Web API (OAuth 2.0) |
| Speaker | Sonos — UPnP/SOAP and Sonos Cloud API |
| AI scripts | Groq API (LLaMA 3.3-70B) |
| TTS | Edge TTS · ElevenLabs · Piper |
| Audio processing | FFmpeg |
| Web server | Nginx + PHP-FPM |

---

## Requirements

- PHP 8.4 with extensions: `ctype`, `iconv`, `curl`, `json`, `pdo_mysql`, `pcntl`, `posix`, `mbstring`, `xml`, `intl`, `openssl`
- MySQL 8.0 or MariaDB
- Nginx + PHP-FPM
- FFmpeg
- Python 3 + pip (`edge-tts` or `piper-tts` depending on your TTS provider)
- Composer

---

## Installation

```bash
# Clone to the server
git clone <repo> /var/www/srs-radio
cd /var/www/srs-radio

# Copy and fill in your credentials
cp .env .env.local
nano .env.local

# Full server install (installs PHP, Nginx, MySQL, etc.)
sudo ./deploy.sh

# Or, if dependencies are already installed, just update
sudo ./deploy.sh update
```

After deploying, authenticate the third-party integrations:

1. Visit `https://your-host/spotify/connect` to authorise Spotify
2. Visit `https://your-host/sonos/connect` to authorise the Sonos Cloud API
3. Run `php bin/console check-setup.sh` to validate the full setup

---

## Configuration

All configuration lives in `.env.local` (never committed). Key variables:

```dotenv
# Spotify
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
SPOTIFY_REDIRECT_URI=https://your-host/spotify/callback

# Sonos
SONOS_IP=192.168.1.x          # local IP of the speaker
SONOS_CLIENT_ID=
SONOS_CLIENT_SECRET=
SONOS_REDIRECT_URI=https://your-host/sonos/callback

# AI / TTS
GROQ_API_KEY=
DJ_LANGUAGE=nl                 # en or nl
DJ_TTS_PROVIDER=edge           # edge | elevenlabs | piper
DJ_VOICE=nl-NL-MaartenNeural
DJ_SERVER_URL=http://192.168.1.x:8080   # URL the Sonos speaker can reach
DJ_BED_FILE=public/sounds/fillers/dj-bed.mp3
DJ_BED_VOLUME=0.20

# Jira (optional)
JIRA_HOST=https://your-company.atlassian.net
JIRA_USER=you@company.nl
JIRA_TOKEN=

# Weather (optional)
WEATHER_API_KEY=
WEATHER_LOCATION=Amsterdam,NL

# News
NEWS_FEED_URL=https://www.nu.nl/rss
```

---

## Running the radio

```bash
# Start the station
php bin/console radio:start

# Start at a specific time
php bin/console radio:start --start-at 09:00

# Choose a Spotify Connect device
php bin/console radio:start --device "Living Room"

# Skip the current track
php bin/console radio:next

# Adjust volume (0-100 or up/down)
php bin/console radio:volume 60
php bin/console radio:volume up

# Stop gracefully
php bin/console radio:stop
```

### Jira alarm monitor (run in a separate process)

```bash
php bin/console jira:monitor
```

---

## All CLI commands

| Command | Description |
|---|---|
| `radio:start` | Start the autonomous radio station |
| `radio:stop` | Stop the station gracefully |
| `radio:next` | Skip to the next track |
| `radio:volume [level\|up\|down]` | Get or set volume |
| `radio:devices` | List available Spotify Connect devices |
| `radio:dj-test [type]` | Generate and play a test DJ clip |
| `radio:dj-voices` | List available edge-tts voices |
| `radio:test-birthday [name]` | Test the full birthday flow |
| `jira:monitor` | Start the Jira polling daemon |

---

## Web UI

| Route | Description |
|---|---|
| `/` | Live radio dashboard |
| `/colleagues` | Add / remove colleagues and birthday dates |
| `/spotify/connect` | Spotify OAuth flow |
| `/sonos/connect` | Sonos OAuth flow |
| `/api/now-playing` | JSON endpoint polled by the dashboard |
| `/api/jira-tickets` | JSON endpoint for the Jira alert panel |

---

## How it works

1. `radio:start` enters a loop: pick track → play via Sonos/Spotify → poll progress → near end, generate next DJ clip (Groq → TTS → FFmpeg mix) → play clip → repeat
2. Scheduled events (weather, news, birthdays) are checked each iteration and injected at the next song boundary
3. `jira:monitor` runs in parallel, writing `var/jira-alarm.json` when a new critical ticket appears; the main loop reads and plays the siren
4. The web dashboard polls `/api/now-playing` every second near end-of-track and every 5 seconds otherwise; it plays DJ clips as an `<audio>` element with smooth volume ramping
5. Inter-process communication uses PID files (`var/radio.pid`) and JSON state files (`var/radio-state.json`)

---

## Database entities

| Entity | Purpose |
|---|---|
| `Track` | Log of every track played (title, artist, Spotify ID, DJ text, timestamp) |
| `Colleague` | Name, birthdate, and optional photo for birthday announcements |
| `DjAnnouncement` | Archive of generated DJ clips (text, audio URL, type, timestamp) |
| `SpotifyToken` | Stored Spotify OAuth tokens (auto-refreshed) |
| `SonosToken` | Stored Sonos Cloud API tokens (auto-refreshed) |

```bash
# Apply migrations
php bin/console doctrine:migrations:migrate
```

---

## Pre-flight check

```bash
bash check-setup.sh
```

Validates PHP extensions, external tools, database connection, environment variables, file permissions, Spotify/Sonos token validity, and pending migrations.
