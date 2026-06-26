# Occasion-sync extension (`src_ext`)

A small, self-contained Baïkal extension that turns contact **birthdays** and
**anniversaries** into calendar reminders, plus a **backup** tool — all kept
**outside** the upstream `src/` submodule so Baïkal can be upgraded without
touching this code.

## What it does

For every user, **if that user has a calendar named `Important Dates`**, the
extension scans the user's contacts (CardDAV address books) and, for each contact
with a `BDAY` and/or `ANNIVERSARY`, maintains entries in that calendar:

- All-day events, with a reminder (`VALARM`) that fires at **08:00** local time.
- Title: `"<contact name>'s Birthday"` / `"<contact name>'s Anniversary"`.

When the source **year is known**, the contact gets up to **three objects** so
the age appears on the most recent and the next celebration only:

1. **Last occurrence** — a single (non-recurring) event on the most recent past
   date (within the last year), with the age/years reached then. This keeps a
   birthday/anniversary that *just passed* on the calendar instead of removing it
   the next day. (Omitted when there is no past occurrence yet, e.g. born this
   year.)
2. **Next occurrence** — a single (non-recurring) event on the upcoming date,
   with the age/years in the title, e.g. `"Bob's Birthday (41)"`.
3. **Series** — a yearly recurring event covering every later year, titled with
   the name only (`"Bob's Birthday"`).

So the most recent and upcoming birthdays show the age, while +1/+2/+3 years out
show just the name. Each daily run rolls this forward: once the date passes,
"last" and "next" advance by a year (new ages) and the series shifts to start the
year after. When the year is **unknown**, a single name-only recurring event is
used (which already covers past years).

It runs per user: a user's contacts only ever produce events in that same user's
calendar. Birthdays and anniversaries are tracked independently (separate
URI/UID prefixes), so toggling one never disturbs the other.

### Title templates & age

Titles are built from templates with two tokens: `{name}` and the count
(`{age}` for birthdays, `{years}` for anniversaries — interchangeable). When a
template has **no** count token but `SHOW_AGE`/`SHOW_YEARS` is on and the year is
known, `(N)` is appended automatically. Examples:

| Template | Year known | Result |
|----------|-----------|--------|
| `{name}'s Birthday` (default, show-age on) | yes | `Bob's Birthday (41)` |
| `{name}'s Birthday` (default) | no | `Bob's Birthday` |
| `{name} turns {age}` | yes | `Bob turns 41` |
| `{name}'s Anniversary` (default, show-years on) | yes | `Al's Anniversary (10)` |

The count is computed per occurrence: the single "last" and "next" events carry
the age/years reached then, while the recurring series stays name-only. The daily
cron keeps them correct as years roll over.

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

- Managed events use stable URI/UID prefixes (`baikal-ext-bday-` for birthdays,
  `baikal-ext-anniv-` for anniversaries; the single past/upcoming events add
  `-last` / `-next` suffixes) and carry an `X-BAIKAL-EXT-SIG` signature and an
  `X-BAIKAL-EXT-ROLE` (`last` / `next` / `series`).
- Re-runs **create** new occasions, **update** changed ones, leave unchanged ones
  alone, and **delete** events whose source contact/field disappeared.
- Events without those prefixes (i.e. anything a user created) are never touched.

## Triggering: startup, event-driven, and a periodic backstop

The extension supports three complementary triggers:

1. **On startup** — an entrypoint hook (`58-run-baikal-sync-on-start.sh`) waits
   until the database is ready (`bin/db-ready.php` probes that the config loads,
   PDO connects, and the schema exists — so it works for SQLite *and* a remote
   MySQL/PostgreSQL that is still starting), then runs one full sync. This seeds
   events on first boot and **migrates events from older extension versions** to
   the current format. The wait runs in the background, so Apache is never
   delayed. Bounded by `BAIKAL_EXT_BIRTHDAY_START_TIMEOUT` (default 300s).
2. **Event-driven (no polling)** — a tiny sabre/dav plugin
   (`BaikalExt\Dav\ChangeTriggerPlugin`) is registered inside Baïkal's DAV server.
   On every contact create/update/delete it writes a small per-user marker into a
   queue directory. A watcher daemon (`bin/birthday-watch`) **blocks on inotify**
   and, when a marker appears, runs the sync for just that one user. Nothing polls
   the database.
3. **Periodic cron** — a daily backstop that catches the yearly date rollover and
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
│   ├── birthdays.php      # CLI entrypoint (birthdays + anniversaries)
│   ├── db-ready.php       # DB readiness probe for the startup sync
│   ├── baikal-backup      # backup wrapper (drops to www-data), on PATH
│   └── backup-db.php      # backend-aware DB snapshot
└── lib/
    ├── Config.php         # baikal.yaml + PDO + options
    ├── Logger.php
    ├── BirthdayParser.php # vCard BDAY/ANNIVERSARY → month/day/year
    ├── BirthdayService.php# scan + reconcile logic (all occasions)
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

In the container image a cron job is installed from `BAIKAL_EXT_BIRTHDAY_CRON`
(default `30 0 * * *`, daily at 00:30). Logs are written to the container's
stdout/stderr so they appear in `docker logs`.

## Configuration

| Variable | Default | Purpose |
|----------|---------|---------|
| `BAIKAL_EXT_BIRTHDAY_WATCH` | `true` | Enable the event-driven watcher |
| `BAIKAL_EXT_BIRTHDAY_WATCH_DEBOUNCE` | `3` | Seconds to coalesce a burst of changes |
| `BAIKAL_EXT_QUEUE` | `<Specific>/birthday-queue` | Marker queue directory |
| `BAIKAL_EXT_BIRTHDAY_ENABLED` | `true` | Enable the periodic cron backstop |
| `BAIKAL_EXT_BIRTHDAY_CRON` | `30 0 * * *` | Cron schedule for the sync |
| `BAIKAL_EXT_BIRTHDAY_CALENDAR` | `Important Dates` | Destination calendar (by display name) |
| `BAIKAL_EXT_BIRTHDAY_ADDRESSBOOK` | _(all)_ | Restrict the scan to one address book |
| `BAIKAL_EXT_BIRTHDAY_ALARM_TIME` | `08:00` | Reminder time (`HH:MM`, local) |
| `BAIKAL_EXT_BIRTHDAY_CREATE_CALENDAR` | `false` | Auto-create the calendar if missing |
| `BAIKAL_EXT_BIRTHDAY_RUN_ON_START` | `true` | Run one full sync on startup once the DB is ready (also migrates old events) |
| `BAIKAL_EXT_BIRTHDAY_START_TIMEOUT` | `300` | Max seconds to wait for the DB on startup |
| `BAIKAL_EXT_BIRTHDAY_TITLE_TEMPLATE` | `{name}'s Birthday` | Birthday title template (`{name}`, `{age}`) |
| `BAIKAL_EXT_BIRTHDAY_SHOW_AGE` | `true` | Append `(age)` when the birth year is known |
| `BAIKAL_EXT_ANNIVERSARY_ENABLED` | `true` | Also sync `ANNIVERSARY` / `X-ANNIVERSARY` |
| `BAIKAL_EXT_ANNIVERSARY_TITLE_TEMPLATE` | `{name}'s Anniversary` | Anniversary title template (`{name}`, `{years}`) |
| `BAIKAL_EXT_ANNIVERSARY_SHOW_YEARS` | `true` | Append `(years)` when the year is known |
| `BAIKAL_PATH_CONFIG` | `/var/www/baikal/config` | Baïkal config directory |

By default, a user without an `Important Dates` calendar is simply skipped (as
specified). Set `BAIKAL_EXT_BIRTHDAY_CREATE_CALENDAR=true` to have it created.

## Backups

`baikal-backup` writes a rotating, timestamped `tar.gz` containing a **consistent
database snapshot** plus a copy of the config directory:

- **SQLite** — uses `VACUUM INTO`, so the snapshot is consistent even while
  Baïkal is serving requests (no downtime, no lock contention).
- **MySQL / PostgreSQL** — uses `mysqldump` / `pg_dump` when those clients are
  available in the image; otherwise it logs a clear message (dump externally).

Run it manually from inside the container:

```bash
baikal-backup            # create one archive now, then prune to the keep limit
```

A cron job is installed from `BAIKAL_EXT_BACKUP_CRON` (default `0 3 * * *`, daily at
03:00). Archives land under `BAIKAL_EXT_BACKUP_DIR` (default `<Specific>/backups`,
i.e. inside the persisted volume).

To restore: extract an archive, replace `baikal.yaml` from `config/`, and restore
the database (`db.sqlite` → the configured SQLite path, or `dump.sql` via
`mysql`/`psql`).

| Variable | Default | Purpose |
|----------|---------|---------|
| `BAIKAL_EXT_BACKUP_ENABLED` | `true` | Install the backup cron job |
| `BAIKAL_EXT_BACKUP_CRON` | `0 3 * * *` | Cron schedule for backups |
| `BAIKAL_EXT_BACKUP_DIR` | `<Specific>/backups` | Where archives are written |
| `BAIKAL_EXT_BACKUP_KEEP` | `7` | How many archives to retain |
| `BAIKAL_EXT_BACKUP_RUN_ON_START` | `false` | Also back up shortly after container start |
