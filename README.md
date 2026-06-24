# Baïkal Docker

Docker image for [Baïkal](https://sabre.io/baikal/) built from the upstream source kept as a git submodule in `src/`. The submodule tracks [sabre-io/Baikal](https://github.com/sabre-io/Baikal); this repository only adds container packaging at the root.

## Prerequisites

Initialize the submodule before building:

```bash
git submodule update --init --recursive
```

## Quick start

```bash
docker compose up -d --build
```

Open [http://localhost:8080/admin/install/](http://localhost:8080/admin/install/) and complete the web installer.

Persistent data is stored in Docker volumes:

- `config/` — `baikal.yaml` and local configuration
- `Specific/` — SQLite database (default backend) and other instance data

## Build for your architecture

```bash
docker build -t baikal:local .
```

## Multi-architecture images

Official `php` and `composer` base images publish manifests for `linux/amd64`, `linux/arm64`, `linux/arm/v7`, and more. Use [Docker Buildx](https://docs.docker.com/build/building/multi-platform/) to build and push a multi-platform image:

```bash
docker buildx create --name baikal-builder --use 2>/dev/null || docker buildx use baikal-builder
docker buildx build \
  --platform linux/amd64,linux/arm64,linux/arm/v7 \
  -t ghcr.io/you/baikal:latest \
  --push \
  .
```

Load a single-platform image into the local daemon:

```bash
docker buildx build --platform linux/amd64 -t baikal:local --load .
```

## Optional MySQL backend

Start Baïkal with a MySQL server:

```bash
docker compose --profile mysql up -d --build
```

During installation, use:

| Setting  | Value   |
|----------|---------|
| Host     | `mysql` |
| Database | `baikal` (or `MYSQL_DATABASE`) |
| User     | `baikal` (or `MYSQL_USER`) |
| Password | `baikal` (or `MYSQL_PASSWORD`) |

Override defaults with a `.env` file:

```env
BAIKAL_HTTP_PORT=8080
MYSQL_ROOT_PASSWORD=change-me
MYSQL_DATABASE=baikal
MYSQL_USER=baikal
MYSQL_PASSWORD=change-me
```

## Birthday reminders extension

This image bundles a small extension (kept in `src_ext/`, separate from the
upstream `src/` submodule) that turns contact birthdays into calendar reminders.

For each user that has a calendar named **`Important Dates`**, it scans that
user's contacts and, for every contact with a birthday, maintains an all-day,
yearly event titled `"<name>'s Birthday"` with a reminder at **08:00**. It runs
per user (a user's contacts only affect that same user's calendar) and only ever
touches events it created itself.

It is **event-driven**: a small plugin inside Baïkal's DAV server reacts to
contact changes and triggers a per-user sync within seconds — no polling. A daily
cron job runs as a backstop (for the yearly date rollover / missed events). You
can also trigger it manually inside the container:

```bash
docker compose exec baikal baikal-birthdays run          # sync everyone
docker compose exec baikal baikal-birthdays run --dry-run -v
docker compose exec baikal baikal-birthdays run --user=alice
```

> The plugin is added to Baïkal via a small patch applied at build time
> (`src_ext/patches/`), so the `src/` submodule stays untouched and the change
> survives Baïkal upgrades.

Configuration (all optional, set in `docker-compose.yml` or `.env`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `BAIKAL_BIRTHDAY_WATCH` | `true` | Event-driven watcher (reacts to contact changes) |
| `BAIKAL_BIRTHDAY_WATCH_DEBOUNCE` | `3` | Seconds to coalesce a burst of changes |
| `BAIKAL_BIRTHDAY_ENABLED` | `true` | Periodic cron backstop |
| `BAIKAL_BIRTHDAY_CRON` | `30 0 * * *` | Cron schedule |
| `BAIKAL_BIRTHDAY_CALENDAR` | `Important Dates` | Destination calendar name |
| `BAIKAL_BIRTHDAY_ALARM_TIME` | `08:00` | Reminder time (`HH:MM`) |
| `BAIKAL_BIRTHDAY_ADDRESSBOOK` | _(all)_ | Restrict scan to one address book |
| `BAIKAL_BIRTHDAY_CREATE_CALENDAR` | `false` | Auto-create the calendar if missing |
| `BAIKAL_BIRTHDAY_RUN_ON_START` | `false` | Also run once shortly after start |

See [`src_ext/README.md`](src_ext/README.md) for design details. The extension
reuses Baïkal's own bundled `sabre/dav` and `sabre/vobject` libraries, so it
stays compatible with newer Baïkal versions and works across the SQLite, MySQL
and PostgreSQL backends.

## High availability

The stack is configured to keep Baïkal running through the common failure modes:

| Failure mode | What recovers it |
|--------------|------------------|
| Process crash / non-zero exit | `restart: always` — the runtime relaunches the container automatically |
| Process alive but unresponsive (hung Apache/PHP) | `HEALTHCHECK` marks it `unhealthy`, and the `autoheal` sidecar restarts it |
| Host / daemon reboot | Docker daemon relaunches `restart: always` containers; for Podman use the systemd unit (below) |

### How it works

- **Image + compose healthcheck** — `curl http://127.0.0.1/` runs every 30s. The root path redirects through PHP, so a passing probe proves Apache *and* PHP are serving, not just answering TCP.
- **`restart: always`** — applies on crash and (for Docker) on boot. A manual `stop` is respected and will not be auto-restarted.
- **`autoheal`** — the container runtime's restart policy only reacts to a process that *exits*. A process that hangs stays "up" forever. The [`willfarrell/autoheal`](https://github.com/willfarrell/autoheal) sidecar watches every container labelled `autoheal=true` and restarts any that report `unhealthy`.

### Verifying recovery

```bash
# Crash the app process; it should come back on its own.
docker compose kill -s SIGKILL baikal     # or: podman-compose kill baikal
docker compose ps                          # baikal returns to "Up (healthy)"

# Watch the health status flip back to healthy.
docker inspect --format '{{.State.Health.Status}}' <container>
```

> Note: `docker stop` / `podman stop` are treated as intentional and are **not** auto-restarted — only crashes and unhealthy states are.

### autoheal and Podman

`autoheal` talks to the container socket. Docker exposes it at `/var/run/docker.sock` (the default). For rootless Podman, enable and point at the Podman socket:

```bash
systemctl --user enable --now podman.socket
DOCKER_SOCK=/run/user/$(id -u)/podman/podman.sock docker-compose up -d
```

### Surviving a reboot

- **Docker**: ensure the daemon starts at boot (`sudo systemctl enable --now docker`). Containers with `restart: always` come back automatically.
- **Podman** (daemonless): nothing starts containers at boot on its own. Install the provided systemd unit, which supervises the whole compose stack and restarts it on failure and after reboot:

```bash
# rootless example
loginctl enable-linger "$USER"
mkdir -p ~/.config/systemd/user
sed "s|/REPO/PATH|$(pwd)|" systemd/baikal.service > ~/.config/systemd/user/baikal.service
systemctl --user daemon-reload
systemctl --user enable --now baikal.service
```

See `systemd/baikal.service` for rootful instructions and Docker variants.

## Environment variables

| Variable | Description |
|----------|-------------|
| `BAIKAL_SERVERNAME` | Apache `ServerName` (e.g. `dav.example.com`) |
| `BAIKAL_SERVERALIAS` | Apache `ServerAlias` (space-separated aliases) |
| `BAIKAL_SKIP_CHOWN` | If set, skip `chown` on `config/` and `Specific/` at startup |

## Updating Baïkal

Pull the latest upstream release in the submodule, then rebuild:

```bash
cd src
git fetch origin
git checkout <tag-or-commit>
cd ..
docker compose build --no-cache
docker compose up -d
```

## Layout

```
.
├── Dockerfile              # multi-stage build from src/
├── docker-compose.yml
├── docker/
│   ├── apache.conf
│   └── docker-entrypoint.sh
├── systemd/
│   └── baikal.service      # boot-persistent supervisor (esp. for Podman)
├── src_ext/                # birthday-reminders extension (not part of submodule)
└── src/                    # git submodule → sabre-io/Baikal
```

## License

Baïkal is licensed under GPL-3.0. See `src/LICENSE`.
