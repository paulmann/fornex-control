# Configuration

The panel reads one file of `KEY=VALUE` lines. Search order:

1. the path in `FORNEX_ENV`;
2. `.env` in the application root;
3. `fornex.env` one level above the application root (recommended: outside the web root).

If no file exists, real environment variables are used — that is how the container and the
CI smoke test run. A real environment variable always wins over the file, so a value can be
overridden for one run without editing anything.

Relative paths in `DB_PATH`, `LOG_PATH`, `SNAPSHOT_DIR` are resolved from the application
root; absolute paths are used as given.

## Application

| Key | Default | Effect |
|---|---|---|
| `APP_NAME` | `Fornex Control` | title of the window and the page header |
| `APP_ENV` | `production` | `development` shows exception messages in responses, `production` does not |
| `APP_VERSION` | `1.1.0` | shown in the interface, in `GET /api/health` and in asset URLs (`?v=`) |
| `APP_URL` | `http://localhost:8080/` | canonical URL, used in messages only |
| `APP_BASE` | empty | URL sub-path when the application is not in the site root, e.g. `/fornex`; the SPA prefixes its requests with it |
| `APP_TIMEZONE` | `UTC` | time zone for timestamps written by the panel |

## Fornex API

| Key | Default | Effect |
|---|---|---|
| `FORNEX_API_KEY` | — | **required**: the panel refuses to start without it. Read from `ru.fornex.com` → "My" → API |
| `FORNEX_BASE_URL` | `https://ru.fornex.com/api` | the public API lives on the `ru.` host; `fornex.com/api/` answers 302/403 |
| `FORNEX_TIMEOUT` | `30` | seconds per HTTP request |
| `FORNEX_RETRIES` | `2` | retries for network errors and 5xx only, never for a business error |
| `FORNEX_USER_AGENT` | `fornex-control/1.1` | requests without a browser-like `User-Agent` hang on TLS at the Fornex side |
| `FORNEX_DEFAULT_IP` | empty | pre-filled value for the "site on VPS" and "mail on VPS" presets; empty makes the field mandatory |

## Login

| Key | Default | Effect |
|---|---|---|
| `ADMIN_LOGIN` | `admin` | login name |
| `ADMIN_PASSWORD_HASH` | empty | `password_hash()` output; empty means login is refused |
| `AUTO_LOGIN_IP` | empty | an address that gets a session without a password; empty disables it |
| `SESSION_NAME` | `fnx_session` | cookie name |
| `SESSION_IDLE` | `1800` | seconds of inactivity before logout |
| `SESSION_SECURE` | `1` | cookie only over HTTPS; set `0` only for a local HTTP instance |

Generate a hash:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Changing the password means replacing the hash and restarting PHP-FPM (the session cookie
survives, but the old password stops working immediately).

`AUTO_LOGIN_IP` grants write access from that address without a password. It is a
convenience for a workstation inside a network you control, not an authentication method:
leave it empty unless both the network and the address are yours.

## Local state

| Key | Default | Effect |
|---|---|---|
| `DB_PATH` | `var/fornex.sqlite` | audit, snapshot index, API tokens, login attempts |
| `LOG_PATH` | `var/log/app.log` | rotating log; `log.rotate_bytes` = 5 MB, `log.keep_files` = 5 |
| `LOG_LEVEL` | `info` | `debug`, `info`, `warning`, `error` |
| `SNAPSHOT_DIR` | `var/snapshots` | one directory per domain with the BIND file and the JSON of record ids |

The directory holding the database and the log must be writable by the PHP user and must not
be reachable over HTTP. `var/` is excluded by `.gitignore`.

## Verification

| Key | Default | Effect |
|---|---|---|
| `VERIFY_RESOLVER` | `8.8.8.8` | public resolver used for the "what the world sees" half of the report |
| `VERIFY_AUTHORITATIVE` | `ns1.fornex.com` | Fornex's authoritative server: shows the zone as it is, not as resolvers cached it |

## Security limits (in `backend/config/defaults.php`)

| Key | Default | Effect |
|---|---|---|
| `security.login_attempts` | `10` | failed logins per address before `429` |
| `security.login_window` | `900` | the window in seconds |

These two are code defaults and are not exposed as environment keys; change them in
`backend/config/defaults.php` if you need a different policy.

There is **no rate limit on the API itself**: the panel is meant to sit behind a web server
that enforces one, and behind an address allow-list. Do not expose it to the open internet
without both.

## What is not configurable, on purpose

- The zone is never cached locally: it is read from the API on every request.
- `SOA` and `NS` records cannot be edited from the panel or the CLI.
- Deletion is by numeric `id` only; there is no setting that turns a pattern delete on.
- A snapshot is taken before a write and cannot be switched off.
