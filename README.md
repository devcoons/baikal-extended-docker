# Baïkal Extended (Docker)

A production-ready, multi-architecture Docker image for [Baïkal](https://sabre.io/baikal/)
— the lightweight CalDAV + CardDAV server — with two things added on top of the
upstream project:

1. **A self-healing container setup** (healthcheck, auto-restart, reboot persistence).
2. **An occasion-reminders extension** that turns contact birthdays and
   anniversaries into calendar reminders, event-driven and per user.
3. **Automated backups** of the database and config, rotated on a schedule.

Upstream Baïkal is kept untouched as a git submodule in [`src/`](src/) (tracking
[sabre-io/Baikal](https://github.com/sabre-io/Baikal)); everything this project
adds lives at the repository root, so Baïkal can be upgraded cleanly.

Published image: **`docker.io/devcoons/baikal-extended`**

---

## Contents

- [What you get](#what-you-get)
- [Quick start (published image)](#quick-start-published-image)
- [Quick start (build it yourself)](#quick-start-build-it-yourself)
- [First-time setup](#first-time-setup)
- [Birthday & anniversary reminders](#birthday--anniversary-reminders)
- [Backups](#backups)
- [Behind a reverse proxy (Caddy + HTTPS)](#behind-a-reverse-proxy-caddy--https)
- [Database backends](#database-backends)
- [Bringing your own SQLite database](#bringing-your-own-sqlite-database)
- [High availability](#high-availability)
- [Configuration reference](#configuration-reference)
- [Building & publishing](#building--publishing)
- [Updating Baïkal](#updating-baïkal)
- [Project layout](#project-layout)
- [License](#license)

---

## What you get

- **Baïkal** on Apache + PHP 8.2 (CalDAV, CardDAV, admin UI).
- **Multi-arch**: builds for `linux/amd64`, `linux/arm64`, `linux/arm/v7`, etc.
- **Birthday & anniversary reminders**: for any user with a calendar named
  `Important Dates`, every contact with a birthday/anniversary gets an all-day
  `"<name>'s Birthday"` / `"<name>'s Anniversary"` reminder at 08:00. When the
  year is known, the **upcoming** occurrence shows the age (`"Bob's Birthday
  (41)"`) while the recurring series for later years shows just the name.
  Event-driven (reacts to contact changes within seconds) with a daily cron
  backstop.
- **Automated backups**: a daily, rotated snapshot of the database (consistent,
  zero-downtime for SQLite via `VACUUM INTO`) plus the config.
- **Self-healing**: container healthcheck, `restart: always`, an `autoheal`
  sidecar for hung processes, and a systemd unit for reboot persistence.
- **Backend-agnostic**: works with SQLite (default), MySQL, or PostgreSQL.

---

## Quick start (published image)

The fastest way to run it — no build required:

```bash
docker run -d --name baikal \
  -p 8080:80 \
  -v baikal-config:/var/www/baikal/config \
  -v baikal-specific:/var/www/baikal/Specific \
  docker.io/devcoons/baikal-extended:0.11.1
```

Then open <http://localhost:8080/admin/install/> and complete the web installer.

Or with Compose:

```yaml
name: baikal

services:
  baikal:
    image: docker.io/devcoons/baikal-extended:0.11.1
    container_name: baikal
    restart: always
    ports:
      - "8080:80"
    volumes:
      - baikal-config:/var/www/baikal/config
      - baikal-specific:/var/www/baikal/Specific
    environment:
      - TZ=Europe/Paris

volumes:
  baikal-config:
  baikal-specific:
```

```bash
docker compose up -d
```

---

## Quick start (build it yourself)

The repository ships a full `docker-compose.yml` (with the HA setup and the
birthday extension wired in). Initialize the submodule first, then build:

```bash
git submodule update --init --recursive
docker compose up -d --build
```

Open <http://localhost:8080/admin/install/> and complete the installer.

Change the host port with `BAIKAL_HTTP_PORT` (default `8080`):

```bash
BAIKAL_HTTP_PORT=9000 docker compose up -d --build
```

> Using Podman? `podman-compose up -d --build` works too. The image is built
> and tested with both Docker and Podman.

---

## First-time setup

1. Browse to `/admin/install/` and follow the wizard.
2. Choose a database backend (SQLite is the default and needs no extra service).
3. Set the admin password and create your user accounts.
4. In each client (Thunderbird, iOS, DAVx5, …), point CalDAV/CardDAV at:
   - `http(s)://<host>/dav.php/`
   - Auto-discovery via `/.well-known/caldav` and `/.well-known/carddav` is
     handled by the image.

Persistent data lives in two volumes:

| Path | Contents |
|------|----------|
| `/var/www/baikal/config` | `baikal.yaml` and local configuration |
| `/var/www/baikal/Specific` | SQLite database and instance data |

---

## Birthday & anniversary reminders

The headline feature. For **each user that has a calendar named `Important Dates`**,
the extension scans that user's contacts and, for every contact with a `BDAY`
and/or `ANNIVERSARY`, maintains an entry in that calendar:

- All-day events with a reminder that fires at **08:00** local time
- Title: `"<contact name>'s Birthday"` / `"<contact name>'s Anniversary"`
- When the source year is known, you get **two objects**: a single **next
  occurrence** event carrying the age/years (`"Bob's Birthday (41)"`) and a
  **yearly series** for all later years titled with the name only. So the
  upcoming celebration shows the age while future years show just the name; the
  daily run rolls this forward automatically as dates pass.

Titles are configurable via templates (`{name}` plus `{age}`/`{years}`), e.g. set
`BAIKAL_EXT_BIRTHDAY_TITLE_TEMPLATE="{name} turns {age}"` for `"Bob turns 41"`.
Anniversaries can be turned off with `BAIKAL_EXT_ANNIVERSARY_ENABLED=false`.

It is strictly per user (a user's contacts only ever write to that same user's
calendar) and only ever touches events it created itself — your own calendar
entries are never modified. Birthdays and anniversaries are tracked independently.

### How it's triggered

- **On startup:** once the database is ready (works for SQLite and MySQL/
  PostgreSQL), the container runs one full sync. This seeds events on first boot
  and **migrates events created by older versions** to the current format. It
  waits in the background, so Apache is never delayed. Controlled by
  `BAIKAL_EXT_BIRTHDAY_RUN_ON_START` (default `true`) and bounded by
  `BAIKAL_EXT_BIRTHDAY_START_TIMEOUT` (default 300s).
- **Event-driven (no polling):** a small plugin inside Baïkal's DAV server reacts
  to contact create/update/delete and triggers a sync for just that user within
  seconds.
- **Daily cron backstop** (`30 0 * * *` by default): catches the yearly date
  rollover and anything missed while the watcher was down.
- **Manual**, anytime:

```bash
docker compose exec baikal baikal-birthdays run            # sync everyone
docker compose exec baikal baikal-birthdays run --dry-run -v   # preview only
docker compose exec baikal baikal-birthdays run --user=alice   # one user
docker compose exec baikal baikal-birthdays --help
```

(If you ran the container with `docker run --name baikal`, use
`docker exec baikal baikal-birthdays run`.)

### What happens when a contact changes

| Action | Result on next sync |
|--------|---------------------|
| Contact with a birthday/anniversary added | Event **created** |
| Contact's date/name changed | Event **updated** |
| Contact deleted, or date removed | Event **deleted** |
| Nothing relevant changed | Left **unchanged** |

By default a user **without** an `Important Dates` calendar is skipped. Set
`BAIKAL_EXT_BIRTHDAY_CREATE_CALENDAR=true` to have it created automatically.

### What gets created (example)

For a contact `Bob Stone` with `BDAY = 1985-08-20`, synced on 2026-06-26:

| Object | Date | Recurs? | Title |
|--------|------|---------|-------|
| next occurrence | 2026-08-20 | no | `Bob Stone's Birthday (41)` |
| series | 2027-08-20 | yearly | `Bob Stone's Birthday` |

So the next birthday shows the age; 2027, 2028, … show just the name. After
2026-08-20 passes, the daily run advances the "next" event to 2027 (age 42) and
the series to start 2028 — always exactly one event per year, never duplicated.

### Edge cases handled

- **Unknown year** (`BDAY = --08-20`): a single name-only yearly event (no age,
  no "next" split — there is nothing to count).
- **Leap day** (`Feb 29`): the "next" event lands on the next real Feb 29 (with
  the correct age) and the series recurs on leap years only.
- **Date already passed this year**: the "next" event jumps to next year's date
  and age.
- **No name** (a contact with a date but no `FN`/`N`): skipped.
- **Age display off** (`BAIKAL_EXT_BIRTHDAY_SHOW_AGE=false`): a single name-only
  yearly event, no split.
- **Only the extension's own events are touched** — matched by URI/UID prefix and
  an `X-BAIKAL-EXT-SIG` signature; your own calendar entries are never modified.

The extension reuses Baïkal's own bundled `sabre/dav` and `sabre/vobject`
libraries (rather than touching the database schema directly), so it stays
compatible with newer Baïkal versions and works across all three backends. See
[`src_ext/README.md`](src_ext/README.md) for the design.

---

## Backups

A daily, rotated backup of your data is created automatically. Each run writes a
timestamped `tar.gz` containing a **consistent database snapshot** plus a copy of
the config directory:

- **SQLite** uses `VACUUM INTO`, so the snapshot is consistent even while Baïkal
  is serving requests — no downtime, no locking.
- **MySQL / PostgreSQL** use `mysqldump` / `pg_dump` when those clients are
  present in the image (otherwise the run logs a clear note; dump externally).

Archives default to `Specific/backups/` (inside the persisted volume), keeping
the newest `7`. Trigger one anytime:

```bash
docker compose exec baikal baikal-backup
```

To restore: extract an archive, put `config/baikal.yaml` back, and restore the
database (`db.sqlite` to the SQLite path, or `dump.sql` via `mysql`/`psql`).

Tunables: `BAIKAL_EXT_BACKUP_ENABLED`, `BAIKAL_EXT_BACKUP_CRON`, `BAIKAL_EXT_BACKUP_DIR`,
`BAIKAL_EXT_BACKUP_KEEP`, `BAIKAL_EXT_BACKUP_RUN_ON_START` (see the
[configuration reference](#configuration-reference)).

> The in-server hook is added to Baïkal via a patch applied at build time
> (`src_ext/patches/`), so the `src/` submodule stays pristine and the change
> survives upgrades. If a future Baïkal version moves the hook point, the build
> fails loudly rather than silently dropping the feature.

---

## Behind a reverse proxy (Caddy + HTTPS)

The container serves plain HTTP on port **80**. Put a TLS-terminating proxy in
front of it. Baïkal honors `X-Forwarded-Proto`, so HTTPS URLs are generated
correctly.

`docker-compose.yml`:

```yaml
name: baikal

services:
  baikal:
    image: docker.io/devcoons/baikal-extended:0.11.1
    container_name: baikal
    restart: always
    expose:
      - "80"
    volumes:
      - ./baikal/config:/var/www/baikal/config
      - ./baikal/Specific:/var/www/baikal/Specific
    environment:
      - TZ=Europe/Paris

  caddy:
    image: caddy:2
    container_name: caddy
    restart: always
    ports:
      - "11000:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - ./caddy_data:/data
      - ./caddy_config:/config
    depends_on:
      - baikal

volumes:
  caddy_data:
  caddy_config:
```

Minimal `Caddyfile`:

```caddyfile
your.domain.com {
    reverse_proxy baikal:80
}
```

> Set `TZ` (container clock) and Baïkal's own `system.timezone` (in the installer)
> to the same zone, so the 08:00 birthday reminders fire at the right local time.

---

## Database backends

SQLite is the default and requires nothing extra. To run with **MySQL**, the
bundled compose has a `mysql` profile:

```bash
docker compose --profile mysql up -d --build
```

During installation choose MySQL and use:

| Setting  | Value |
|----------|-------|
| Host     | `mysql` |
| Database | `baikal` (or `MYSQL_DATABASE`) |
| User     | `baikal` (or `MYSQL_USER`) |
| Password | `baikal` (or `MYSQL_PASSWORD`) |

Override defaults via a `.env` file:

```env
BAIKAL_HTTP_PORT=8080
MYSQL_ROOT_PASSWORD=change-me
MYSQL_DATABASE=baikal
MYSQL_USER=baikal
MYSQL_PASSWORD=change-me
```

PostgreSQL is also supported by Baïkal (and the extension); configure it in the
installer.

---

## Bringing your own SQLite database

An existing **Baïkal** SQLite database works as-is, because this image uses the
same schema. To migrate:

1. Place the file where Baïkal expects it (default
   `/var/www/baikal/Specific/db/db.sqlite`):

```bash
mkdir -p ./baikal/Specific/db
cp /path/to/your.sqlite ./baikal/Specific/db/db.sqlite
```

2. Provide a **matching `baikal.yaml`** in `./baikal/config/` — ideally your
   original one. The `auth_realm` must match (default `BaikalDAV`), since user
   passwords are stored as `md5(user:realm:pass)`; a mismatched realm breaks
   client logins.
3. If the DB came from an **older** Baïkal, the first request redirects to
   `/admin/install/` to run the schema upgrade — log in and confirm. Your data
   is preserved.

Quick check that it's a Baïkal database:

```bash
sqlite3 your.sqlite ".tables"
# expect: addressbooks calendars calendarinstances calendarobjects cards principals users ...
```

---

## High availability

The bundled stack keeps Baïkal running through the common failure modes:

| Failure mode | What recovers it |
|--------------|------------------|
| Process crash / non-zero exit | `restart: always` relaunches the container |
| Alive but unresponsive (hung Apache/PHP) | `HEALTHCHECK` marks it `unhealthy`; the `autoheal` sidecar restarts it |
| Host / daemon reboot | Docker daemon relaunches `restart: always` containers; for Podman use the systemd unit |

### How it works

- **Healthcheck** — `curl http://127.0.0.1/` runs every 30s. The root path
  redirects through PHP, so a passing probe proves Apache *and* PHP are serving,
  not just answering TCP.
- **`restart: always`** — applies on crash and (for Docker) on boot. A manual
  `stop` is respected and not auto-restarted.
- **`autoheal`** — the runtime's restart policy only reacts to a process that
  *exits*; a hung process stays "up". The
  [`willfarrell/autoheal`](https://github.com/willfarrell/autoheal) sidecar
  watches containers labelled `autoheal=true` and restarts any that go
  `unhealthy`.

### Verifying recovery

```bash
docker compose kill -s SIGKILL baikal   # simulate a crash
docker compose ps                       # baikal returns to "Up (healthy)"
```

> `docker stop` / `podman stop` are intentional and are **not** auto-restarted —
> only crashes and unhealthy states are.

### autoheal on Podman

`autoheal` talks to the container socket. Docker exposes it at
`/var/run/docker.sock` (the default). For rootless Podman:

```bash
systemctl --user enable --now podman.socket
DOCKER_SOCK=/run/user/$(id -u)/podman/podman.sock podman-compose up -d
```

### Surviving a reboot

- **Docker**: ensure the daemon starts at boot
  (`sudo systemctl enable --now docker`); `restart: always` does the rest.
- **Podman** (daemonless): use one of the provided systemd units, which also
  restart on failure and after reboot.
  - `systemd/baikal.container` — a **Quadlet** unit (recommended): native crash
    recovery + `HealthOnFailure=restart` + boot persistence.
  - `systemd/baikal.service` — supervises the whole compose stack.

```bash
# rootless Quadlet example
loginctl enable-linger "$USER"
mkdir -p ~/.config/containers/systemd
cp systemd/baikal.container ~/.config/containers/systemd/baikal.container
systemctl --user daemon-reload
systemctl --user start baikal
```

See the headers of `systemd/baikal.container` and `systemd/baikal.service` for
rootful and Docker variants.

> Note: the image's built-in `HEALTHCHECK` is honored by Docker. When the image
> is built with Podman's default OCI format the directive is ignored at the image
> level, but the **compose** and **Quadlet** healthchecks cover Podman.

---

## Configuration reference

### Web server

| Variable | Default | Description |
|----------|---------|-------------|
| `BAIKAL_HTTP_PORT` | `8080` | Host port (compose only) |
| `BAIKAL_SERVERNAME` | _(unset)_ | Apache `ServerName` (e.g. `dav.example.com`) |
| `BAIKAL_SERVERALIAS` | _(unset)_ | Apache `ServerAlias` (space-separated) |
| `BAIKAL_SKIP_CHOWN` | _(unset)_ | If set, skip `chown` of `config/` & `Specific/` at startup |
| `TZ` | _(image default)_ | Container timezone |

### Birthday & anniversary extension

| Variable | Default | Purpose |
|----------|---------|---------|
| `BAIKAL_EXT_BIRTHDAY_WATCH` | `true` | Event-driven watcher (reacts to contact changes) |
| `BAIKAL_EXT_BIRTHDAY_WATCH_DEBOUNCE` | `3` | Seconds to coalesce a burst of changes |
| `BAIKAL_EXT_BIRTHDAY_ENABLED` | `true` | Periodic cron backstop |
| `BAIKAL_EXT_BIRTHDAY_CRON` | `30 0 * * *` | Cron schedule |
| `BAIKAL_EXT_BIRTHDAY_CALENDAR` | `Important Dates` | Destination calendar name |
| `BAIKAL_EXT_BIRTHDAY_ALARM_TIME` | `08:00` | Reminder time (`HH:MM`, local) |
| `BAIKAL_EXT_BIRTHDAY_ADDRESSBOOK` | _(all)_ | Restrict the scan to one address book |
| `BAIKAL_EXT_BIRTHDAY_CREATE_CALENDAR` | `false` | Auto-create the calendar if missing |
| `BAIKAL_EXT_BIRTHDAY_RUN_ON_START` | `true` | Run one full sync on startup once the DB is ready (also migrates old events) |
| `BAIKAL_EXT_BIRTHDAY_START_TIMEOUT` | `300` | Max seconds to wait for the DB on startup |
| `BAIKAL_EXT_BIRTHDAY_TITLE_TEMPLATE` | `{name}'s Birthday` | Birthday title (`{name}`, `{age}`) |
| `BAIKAL_EXT_BIRTHDAY_SHOW_AGE` | `true` | Append `(age)` when the birth year is known |
| `BAIKAL_EXT_ANNIVERSARY_ENABLED` | `true` | Also sync `ANNIVERSARY` / `X-ANNIVERSARY` |
| `BAIKAL_EXT_ANNIVERSARY_TITLE_TEMPLATE` | `{name}'s Anniversary` | Anniversary title (`{name}`, `{years}`) |
| `BAIKAL_EXT_ANNIVERSARY_SHOW_YEARS` | `true` | Append `(years)` when the year is known |

### Backups

| Variable | Default | Purpose |
|----------|---------|---------|
| `BAIKAL_EXT_BACKUP_ENABLED` | `true` | Install the backup cron job |
| `BAIKAL_EXT_BACKUP_CRON` | `0 3 * * *` | Cron schedule for backups |
| `BAIKAL_EXT_BACKUP_DIR` | `<Specific>/backups` | Where archives are written |
| `BAIKAL_EXT_BACKUP_KEEP` | `7` | How many archives to retain |
| `BAIKAL_EXT_BACKUP_RUN_ON_START` | `false` | Also back up shortly after start |

---

## Building & publishing

Build a local image:

```bash
docker build -t baikal:local .
```

### Multi-architecture build & push

Official `php`/`composer` base images are multi-arch. Use Buildx:

```bash
docker buildx create --name baikal-builder --use 2>/dev/null || docker buildx use baikal-builder
docker buildx build \
  --platform linux/amd64,linux/arm64,linux/arm/v7 \
  -t docker.io/devcoons/baikal-extended:0.11.1 \
  -t docker.io/devcoons/baikal-extended:latest \
  --push \
  .
```

Load a single-platform image into the local daemon instead of pushing:

```bash
docker buildx build --platform linux/amd64 -t baikal:local --load .
```

### Helper script

`scripts/build-push.sh` builds and tags with both the Baïkal version and
`latest`:

```bash
./scripts/build-push.sh            # build only (auto-detects version tag)
docker login docker.io            # or: podman login docker.io
./scripts/build-push.sh --push     # build and push
./scripts/build-push.sh --push 1.0.0   # custom tag
```

---

## Updating Baïkal

Bump the submodule to a new upstream release and rebuild:

```bash
cd src
git fetch origin
git checkout <tag-or-commit>
cd ..
docker compose build --no-cache
docker compose up -d
```

If the birthday hook patch no longer applies to the new Baïkal sources, the build
fails at the patch step — update `src_ext/patches/` to match.

---

## Project layout

```
.
├── Dockerfile                      # multi-stage build from src/ + extension + patch
├── docker-compose.yml              # full stack: baikal + autoheal (+ optional mysql)
├── docker/
│   ├── apache.conf
│   ├── docker-entrypoint.sh
│   └── docker-entrypoint.d/        # startup hooks (db dir, perms, cron, watcher, backups)
├── src_ext/                        # occasion-reminders + backup extension (NOT in submodule)
│   ├── bootstrap.php               # PSR-4 autoloader for BaikalExt\
│   ├── bin/                        # CLI (baikal-birthdays, baikal-backup) + inotify watcher
│   ├── lib/                        # Config, BirthdayService, sabre plugin, ...
│   ├── patches/                    # build-time patch adding the Baïkal hook
│   └── README.md                   # extension design & details
├── systemd/
│   ├── baikal.container            # Podman Quadlet unit (recommended for Podman)
│   └── baikal.service              # compose-supervisor unit
├── scripts/
│   └── build-push.sh               # build + tag + push helper
└── src/                            # git submodule → sabre-io/Baikal (pristine)
```

---

## License

Baïkal is licensed under GPL-3.0 (see [`src/LICENSE`](src/LICENSE)). The packaging
and extension code in this repository are provided under the same terms.
