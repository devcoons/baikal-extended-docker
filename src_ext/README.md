# Birthday-sync extension (`src_ext`)

A small, self-contained Baïkal extension that turns contact birthdays into
calendar reminders — kept **outside** the upstream `src/` submodule so Baïkal can
be upgraded without touching this code.

## What it does

For every user, **if that user has a calendar named `Important Dates`**, the
extension scans the user's contacts (CardDAV address books) and, for each contact
with a `BDAY`, maintains an entry in that calendar:

- All-day, **yearly recurring** event on the birthday
- Title: `"<contact name>'s Birthday"`
- A reminder (`VALARM`) that fires at **08:00** local time on the day

It runs per user: a user's contacts only ever produce events in that same user's
calendar.

## Design

The extension reuses Baïkal's own bundled libraries rather than hard-coding the
database schema, which keeps it compatible across Baïkal versions:

- **`sabre/dav` PDO backends** (`CardDAV`, `CalDAV`, `DAVACL`) to read contacts
  and read/write calendar objects — synctokens, etags and validation are handled
  by sabre exactly as the live server does.
- **`sabre/vobject`** to parse vCards and build iCalendar events.
- **`symfony/yaml`** to read `baikal.yaml`.

The database connection is built to mirror `Flake\Framework::initDb*`, so it
works with the SQLite, MySQL and PostgreSQL backends transparently.

> The "out of the box" hint about parsing SQLite directly is intentionally
> avoided: going through sabre's backends means we never desync synctokens or
> corrupt etags, and it works unchanged on MySQL/PostgreSQL too.

### Idempotency & safety

- Managed events use a stable URI/UID prefix `baikal-ext-bday-` and carry an
  `X-BAIKAL-EXT-SIG` signature.
- Re-runs **create** new birthdays, **update** changed ones, leave unchanged ones
  alone, and **delete** events whose source contact/`BDAY` disappeared.
- Events without that prefix (i.e. anything a user created) are never touched.

## Triggering: event-driven, with a periodic backstop

The extension supports two complementary triggers:

1. **Event-driven (no polling)** — a tiny sabre/dav plugin
   (`BaikalExt\Dav\ChangeTriggerPlugin`) is registered inside Baïkal's DAV server.
   On every contact create/update/delete it writes a small per-user marker into a
   queue directory. A watcher daemon (`bin/birthday-watch`) **blocks on inotify**
   and, when a marker appears, runs the sync for just that one user. Nothing polls
   the database.
2. **Periodic cron** — a daily backstop that catches the yearly date rollover and
   anything the watcher might have missed (e.g. if it was down).

```
contact PUT/DELETE ──▶ Baïkal DAV server
                         └─▶ ChangeTriggerPlugin  (afterCreateFile/afterWriteContent/afterUnbind)
                               └─▶ writes <queue>/<sha1(user)>  (content = username)
inotify wakes ◀──────────────────┘
   └─▶ birthday-watch ──▶ baikal-birthdays run --user=<user>
```

The marker is intentionally cheap (a single file write) so DAV responses stay
fast, and repeated changes for one user **coalesce** into a single marker. The
watcher debounces briefly to absorb bursts (e.g. a client syncing many contacts).

### The Baïkal hook

Registering a plugin requires one small addition to
`Baikal\Core\Server::initServer()`. To keep the `src/` submodule pristine and
upgrade-safe, that change is **not** committed into the submodule; it lives as a
patch in `patches/0001-birthday-change-trigger-hook.patch` and is applied at
Docker build time. The hook is guarded (`is_readable(bootstrap)` +
`class_exists`), so upstream Baïkal keeps working even without the extension.

## Layout

```
src_ext/
├── bootstrap.php          # PSR-4 autoloader for BaikalExt\ (CLI + web)
├── patches/
│   └── 0001-...patch      # adds the plugin hook to Baikal's Server.php
├── bin/
│   ├── baikal-birthdays   # shell wrapper (drops to www-data), on PATH
│   ├── birthday-watch     # inotify watcher daemon
│   └── birthdays.php      # CLI entrypoint
└── lib/
    ├── Config.php         # baikal.yaml + PDO + options
    ├── Logger.php
    ├── BirthdayParser.php # vCard BDAY → month/day/year
    ├── BirthdayService.php# scan + reconcile logic
    └── Dav/
        └── ChangeTriggerPlugin.php  # sabre plugin → per-user markers
```

## Running it manually

From inside the container:

```bash
baikal-birthdays run               # sync everyone
baikal-birthdays run --dry-run -v  # preview, with per-contact detail
baikal-birthdays run --user=alice  # only one user
baikal-birthdays --help
```

## Periodic execution

In the container image a cron job is installed from `BAIKAL_BIRTHDAY_CRON`
(default `30 0 * * *`, daily at 00:30). Logs are written to the container's
stdout/stderr so they appear in `docker logs`.

## Configuration

| Variable | Default | Purpose |
|----------|---------|---------|
| `BAIKAL_BIRTHDAY_WATCH` | `true` | Enable the event-driven watcher |
| `BAIKAL_BIRTHDAY_WATCH_DEBOUNCE` | `3` | Seconds to coalesce a burst of changes |
| `BAIKAL_EXT_QUEUE` | `<Specific>/birthday-queue` | Marker queue directory |
| `BAIKAL_BIRTHDAY_ENABLED` | `true` | Enable the periodic cron backstop |
| `BAIKAL_BIRTHDAY_CRON` | `30 0 * * *` | Cron schedule for the sync |
| `BAIKAL_BIRTHDAY_CALENDAR` | `Important Dates` | Destination calendar (by display name) |
| `BAIKAL_BIRTHDAY_ADDRESSBOOK` | _(all)_ | Restrict the scan to one address book |
| `BAIKAL_BIRTHDAY_ALARM_TIME` | `08:00` | Reminder time (`HH:MM`, local) |
| `BAIKAL_BIRTHDAY_CREATE_CALENDAR` | `false` | Auto-create the calendar if missing |
| `BAIKAL_BIRTHDAY_RUN_ON_START` | `false` | Also run once shortly after container start |
| `BAIKAL_PATH_CONFIG` | `/var/www/baikal/config` | Baïkal config directory |

By default, a user without an `Important Dates` calendar is simply skipped (as
specified). Set `BAIKAL_BIRTHDAY_CREATE_CALENDAR=true` to have it created.
