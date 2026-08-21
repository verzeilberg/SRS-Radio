# SRS Radio

An autonomous AI-powered radio station for the office. SRS Radio plays music from Spotify through a Sonos speaker (or Spotify Connect device), inserts AI-generated DJ announcements between tracks, monitors Jira for critical tickets, announces the weather and news, celebrates colleague birthdays, accepts listener song requests and notes — all while displaying a live dashboard in the browser.

---

## Features

- **Autonomous playback** — continuously picks and queues tracks from Spotify playlists or top-track pools with duplicate avoidance and seamless transitions
- **AI DJ** — generates natural radio-style announcements using Groq (LLaMA 3.3-70B), voiced via Edge TTS, ElevenLabs, or Piper, mixed with optional background bed music
- **Scheduled segments** — time-triggered announcements: morning (9:00), lunch (12:00), afternoon, Friday wind-down, end of day, weather report, news headlines, WK pool ranking
- **Birthday announcements** — at 11:00 the station reads out a personalised birthday message, plays a dedicated birthday song, and shows a confetti overlay on the dashboard
- **Theme Thursday** — weekly voting cycle (Mon–Wed open, Wed close/announce, Thu plays theme all day at 09:00 with DJ kickoff). 10 themes: 80s, 90s, 00s, Dance, Rock, Dutch, Soul, Disco, House, Techno
- **Jira alarm** — a parallel monitor polls Jira for high-priority tickets and triggers an air-raid siren clip at the next song boundary
- **Listener song requests** — web UI for colleagues to search Spotify and request songs; admins approve/reject; approved requests are announced and played at the next boundary
- **Listener notes** — web UI for colleagues to send short messages read out on air by the DJ
- **Commercial breaks** — place MP3 files in `public/sounds/commercials/` to enable periodic ad breaks (every 4 tracks by default)
- **Live web dashboard** — real-time now-playing display with EQ visualiser, progress bar, next track, DJ text, album art, Jira alert panel, birthday confetti
- **Admin dashboard** — start/stop/restart/pause/skip/volume control, remote radio control, playlist pool management, song request moderation, user management, soccer dates, live logs
- **User dashboard** — song requests, listener notes, Theme Thursday voting
- **Password reset** — forgot password flow with email link
- **Colleague management** — web UI to manage colleagues and their birthdays (with photo upload)
- **Multiple playback backends** — Sonos Cloud API, Sonos UPnP, Spotify Connect, DLNA/UPnP soundbars
- **Remote radio control** — manage a radio instance on another machine over SSH
- **Multiple TTS providers** — Edge TTS, ElevenLabs, Piper (configurable via `DJ_TTS_PROVIDER`)

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
| Mail | Symfony Mailer |

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
3. Run `bash check-setup.sh` to validate the full setup

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
DJ_PIPER_MODEL=var/piper/en_US-ryan-high.onnx   # for Piper TTS
ELEVENLABS_API_KEY=            # for ElevenLabs
ELEVENLABS_VOICE_ID=           # for ElevenLabs

# Jira (optional)
JIRA_HOST=https://your-company.atlassian.net
JIRA_USER=you@company.nl
JIRA_TOKEN=
JIRA_ALARM_ACCOUNT=Support     # custom field value to filter tickets
JIRA_ALARM_LABELS=             # comma-separated labels to filter
JIRA_ALARM_CLIP_URL=http://your-host/sounds/air_raid_siren.mp3

# Weather (optional)
WEATHER_API_KEY=
WEATHER_LOCATION=Amsterdam,NL
WEATHER_HOUR=12
WEATHER_MINUTE=20

# News
NEWS_FEED_URL=https://www.nu.nl/rss

# Remote radio (optional)
REMOTE_RADIO_HOST=
REMOTE_RADIO_USER=
REMOTE_RADIO_DIR=/var/www/srs-radio
REMOTE_RADIO_NAME=

# DLNA soundbar for DJ clips (optional)
DJ_CLIP_IP=192.168.2.4

# Mail (for password reset)
MAILER_DSN=smtp://localhost
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
| `radio:dj-voices` | List available edge-tts voices (filter with `--lang`) |
| `radio:test-birthday [name]` | Test the full birthday flow (popup + audio) |
| `radio:remote [action]` | Control remote radio: `status`, `start`, `stop`, `next` (use `--stop-local` to stop local first) |
| `radio:sonos-api-debug` | Test Sonos Cloud API connection |
| `radio:sonos-info` | Show Sonos device info and configured music service accounts |
| `radio:spotify:login` | Authenticate with Spotify via the console |
| `app:create-user` | Create a new user (`--admin` for ROLE_ADMIN, `--password` to set) |
| `jira:monitor` | Start the Jira polling daemon |
| `theme-vote:open [--force]` | Open Theme Thursday voting for this week (Mon–Wed) |
| `theme-vote:close [--force]` | Close voting & announce winner (Wed) |
| `theme-vote:status` | Show current Theme Thursday voting status |

---

## Web UI

| Route | Description |
|---|---|
| `/` | Live radio dashboard |
| `/dashboard` | User dashboard (song requests, listener notes, Theme Thursday voting) |
| `/colleagues` | Add / remove colleagues and birthday dates |
| `/admin` | Admin dashboard (full control) |
| `/setup` | Connection status for Spotify / Sonos |
| `/spotify/connect` | Spotify OAuth flow |
| `/spotify/callback` | Spotify OAuth callback |
| `/sonos/connect` | Sonos OAuth flow |
| `/sonos/callback` | Sonos OAuth callback |
| `/forgot-password` | Request password reset link |
| `/check-email` | Check email for reset link |
| `/reset-password/{token}` | Reset password via token |
| `/api/now-playing` | JSON endpoint polled by the dashboard |
| `/api/jira-tickets` | JSON endpoint for the Jira alert panel |
| `/api/news-headlines` | News headlines for the dashboard |
| `/api/listener-notes` | GET: list notes, POST: submit a listener note |
| `/api/song-search` | Search Spotify for song requests |
| `/api/song-request` | POST: submit a song request |
| `/api/my-song-requests` | Get current user's song requests |
| `/api/theme-vote` | POST: vote, GET: status |

---

## Admin API

| Route | Description |
|---|---|
| `POST /admin/api/start` | Start the radio |
| `POST /admin/api/stop` | Stop the radio |
| `POST /admin/api/next` | Skip track |
| `POST /admin/api/pause` | Toggle pause |
| `POST /admin/api/restart` | Restart the radio |
| `POST /admin/api/volume/up` | Volume up |
| `POST /admin/api/volume/down` | Volume down |
| `GET /admin/api/state` | Get running/paused state |
| `GET /admin/api/log` | Get recent log lines |
| `GET /admin/api/remote` | Check remote radio status |
| `POST /admin/api/remote/{start\|stop\|next}` | Control remote radio |
| `GET /admin/api/song-requests` | List pending song requests |
| `POST /admin/api/song-request/{id}/approve` | Approve a song request |
| `POST /admin/api/song-request/{id}/reject` | Reject a song request |
| `GET /admin/api/listener-notes` | List listener notes |
| `GET /admin/api/playlists` | Manage playlist pools |
| `POST /admin/api/playlists` | Add a playlist to the pool |
| `DELETE /admin/api/playlists/{id}` | Remove a playlist from the pool |
| `POST /admin/api/playlists/{id}/toggle` | Activate/deactivate a playlist |
| `POST /admin/api/playlists/{id}/theme-thursday` | Set Theme Thursday flag/title |
| `POST /admin/api/playlists/search` | Search Spotify for playlists |
| `POST /admin/api/soccer` | Set soccer pool dates |
| `POST /admin/api/user/{id}/name` | Update user display name |
| `POST /admin/api/user/{id}/delete` | Delete user |
| `POST /admin/api/user/{id}/toggle-role` | Toggle ROLE_ADMIN |

---

## How it works

1. `radio:start` enters a loop: pick track → play via Sonos/Spotify → poll progress → near end, generate next DJ clip (Groq → TTS → FFmpeg mix) → play clip → repeat
2. Scheduled events (weather, news, birthdays, Theme Thursday) are checked each iteration and injected at the next song boundary
3. `jira:monitor` runs in parallel, writing `var/jira-alarm.json` when a new critical ticket appears; the main loop reads and plays the siren
4. The web dashboard polls `/api/now-playing` every second near end-of-track and every 5 seconds otherwise; it plays DJ clips as an `<audio>` element with smooth volume ramping
5. Inter-process communication uses PID files (`var/radio.pid`) and JSON state files (`var/radio-state.json`)
6. DJ clips are **pre-generated during the current track's playback** so they're ready instantly when the track ends — no blocking wait
7. Volume control auto-detects the active backend: Sonos Cloud API → Spotify Connect → DLNA soundbar → Sonos UPnP

---

## Database entities

| Entity | Purpose |
|---|---|
| `Track` | Log of every track played (title, artist, Spotify ID, DJ text, timestamp) |
| `Colleague` | Name, birthdate, and optional photo for birthday announcements |
| `DjAnnouncement` | Archive of generated DJ clips (text, audio URL, type, timestamp) |
| `SpotifyToken` | Stored Spotify OAuth tokens (auto-refreshed) |
| `SonosToken` | Stored Sonos Cloud API tokens (auto-refreshed) |
| `SongRequest` | Listener song requests with approval workflow |
| `ResetPasswordRequest` | Password reset tokens |
| `User` | Web UI users with roles (ROLE_USER, ROLE_ADMIN) |
| `Playlist` | Spotify playlist pools for track selection (supports `themeThursday` + `themeThursdayTitle`) |
| `ThemeVote` | Weekly Theme Thursday voting records |

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

---

## Directory structure

```
src/
├── Command/           # Console commands (radio:*, jira:monitor, theme-vote:*, app:create-user)
├── Controller/        # Web controllers (Radio, Admin, User, Colleague, Auth, Setup)
├── DTO/               # Data transfer objects (DjContext)
├── Entity/            # Doctrine entities
├── Repository/        # Doctrine repositories
├── Service/           # Core services (Spotify, Sonos, DJ, TTS, Weather, News, Jira, RemoteRadio, RadioState)
├── Kernel.php
public/
├── sounds/
│   ├── dj/            # Generated DJ clips (auto-cleaned)
│   ├── fillers/       # Bed music files
│   ├── commercials/   # Commercial MP3s (optional)
│   └── air_raid_siren.mp3
├── images/colleagues/ # Uploaded colleague photos
var/
├── radio.pid          # PID file for radio:start
├── radio-state.json   # Current playback state
├── radio.log          # Console output log
├── jira-alarm.json    # Active Jira alarm
├── jira-state.json    # Jira monitor state
├── listener-notes.json
├── soccer-dates.json  # WK pool start/end dates
├── radio-launch.json  # Last launch device
├── radio-stop.flag    # Stop signal
├── radio-restart.flag # Restart signal
├── radio-skip.flag    # Skip signal
├── radio-pause.flag   # Pause signal
└── cache/             # Symfony cache
```

---

## Customisation

- **DJ styles**: Edit `src/Service/DjScriptService.php` — the `$djStyles` and `$openingBans` arrays control the DJ personality
- **Time events**: Modify the `$timeEvents` array in `RadioStartCommand::__construct()` to change scheduled announcements
- **DJ frequency**: `$djEveryNTracks` (default 2–3) and `$commercialsEveryNTracks` (default 4) in `RadioStartCommand`
- **Playlist pools**: Manage via `/admin` or add `Playlist` entities directly
- **TTS providers**: Switch via `DJ_TTS_PROVIDER` env var; add new providers in `TextToSpeechService::generateVoice()`
- **Theme Thursday themes**: Edit `$allowedThemes` in `UserController::themeVote()` and `RadioStartCommand::getActiveThemeTitle()`

---

## Troubleshooting

| Issue | Solution |
|---|---|
| "Radio is already running" | `php bin/console radio:stop` then wait a moment |
| No sound from Sonos | Check `SONOS_IP`, ensure speaker is on same LAN, run `check-setup.sh` |
| Spotify token expired | Visit `/spotify/connect` to re-authenticate |
| DJ clips not playing | Verify `DJ_SERVER_URL` is reachable from the Sonos speaker (LAN IP, not localhost) |
| `edge-tts` not found | `pip3 install edge-tts` and ensure it's in PATH |
| Database connection failed | Check `DATABASE_URL` in `.env.local`, ensure MySQL is running |
| Volume control not working | Some Spotify Connect devices don't support volume API; falls back to hardware |
| Password reset emails not sent | Configure `MAILER_DSN` in `.env.local` |

---

## Theme Thursday workflow

1. **Monday–Wednesday** — Voting open. Users vote via `/dashboard` or admin opens via `theme-vote:open` or admin UI
2. **Wednesday** — Voting closes. Admin runs `theme-vote:close` or uses admin UI; winner announced
3. **Thursday 09:00** — Radio plays Theme Thursday kickoff DJ announcement, then plays from playlists tagged `themeThursday=true` (or `themeThursdayTitle` matching winner) all day

Playlists are tagged in admin UI or via API:
- `themeThursday=true` — playlist participates in Theme Thursday
- `themeThursdayTitle="Dutch"` — playlist only used when "Dutch" wins (optional)

---

## Remote radio setup

1. On controller machine: set `REMOTE_RADIO_HOST`, `REMOTE_RADIO_USER`, `REMOTE_RADIO_DIR` in `.env.local`
2. Copy SSH key: `ssh-copy-id user@remote-host`
3. Control: `php bin/console radio:remote start --stop-local` (stops local, starts remote)