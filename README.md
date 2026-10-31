# Fornex Control

<!-- md-i18n:switcher -->
**Read this in:** English · [Русский](README.ru.md)

**A self-hosted panel for Fornex DNS zones: a web interface for people, a JSON API and a CLI for scripts — with a snapshot of the zone taken before every write.**

[![CI](https://github.com/paulmann/fornex-control/actions/workflows/ci.yml/badge.svg)](https://github.com/paulmann/fornex-control/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.5](https://img.shields.io/badge/php-8.5-777BB4)](https://www.php.net/)
[![Tests](https://img.shields.io/badge/tests-52%20passing-brightgreen.svg)](tests/plan_test.php)
[![API routes](https://img.shields.io/badge/API-27%20routes-6f42c1)](docs/API.md)
[![CLI commands](https://img.shields.io/badge/CLI-13%20commands-0ea5e9)](docs/CLI.md)

Fornex has a public API for DNS, but a zone is a place where a wrong write is silent: a second SPF
record makes every letter from the domain fail (`permerror`), a second MX on the same target moves
half the mail away from Google, a re-run preset duplicates DKIM, and a missing record is discovered
not by a test but by a bounced letter weeks later. This panel turns zone work into a reviewable
plan: **preview first, apply second, snapshot before each write** — and the same rules for the
interface, the JSON API and the CLI, because they all run through one planner.

> **Documentation status.** Every number in this README was measured on the published tree (`52` checks in `tests/plan_test.php`, `27` routes in `backend/config/routes.php`, `13` CLI commands in `backend/src/Cli/Application.php`). Long-form guides are in the [Wiki](https://github.com/paulmann/fornex-control/wiki) and [`docs/README.md`](docs/README.md).

This project is not affiliated with Fornex. "Fornex" is a trademark of its respective owner.

---

## Contents

- [What it is and why](#what-it-is-and-why)
- [Features](#features)
- [By the numbers](#by-the-numbers)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Command line](#command-line)
- [Mail presets](#mail-presets)
- [Safety rules](#safety-rules)
- [Limits that come from Fornex](#limits-that-come-from-fornex)
- [Documentation](#documentation)
- [Development](#development)
- [FAQ](#faq)
- [Contributing](#contributing)
- [License](#license)

---

## What it is and why

A zone is edited by three kinds of actors: a person in a browser, a cron script, and an agent that
answers a ticket. When each of them has its own code, the rules drift: the interface refuses to
create a second SPF, the script creates one, and the mailbox stops receiving mail. Here the rules
live in one place — `backend/src/Service/ZonePlanner.php` — and everything else calls it.

- **Inventory.** Zones in the account, their records, NS status, delegation state.
- **Records.** A, AAAA, CNAME, MX, TXT, NS, SRV, CAA with validation by type; delete strictly by numeric `id`; `SOA` and `NS` are never touched.
- **Presets.** Google Workspace (4 steps), Yandex 360, site on this VPS, mail on this VPS — with SPF/DMARC merging instead of overwriting.
- **Verification.** NS at the registrar, MX and their targets, SPF, DKIM by four selectors, DMARC, A for `@`/`www`, PTR — resolved against both a public resolver and Fornex's authoritative server.
- **Audit.** Every attempt, dry run and refusal goes to the log with the reason; snapshots are stored as BIND + JSON.
- **The same over HTTP.** 27 API routes; a token can be read-only, and the preview route works even for a read-only token.

Key decisions:

| Decision | Why |
|---|---|
| Zero Composer and npm dependencies | install is `git clone` plus PHP; nothing breaks on a transitive package |
| Preview → apply, snapshot before write | a zone mistake is invisible at the moment of the write |
| SPF/DMARC/DKIM merged, not replaced | the zone often already has mechanisms the preset knows nothing about |
| Delete by numeric `id` only | a mask or a pattern hits a record nobody meant to touch |
| One planner for UI, API and CLI | three entry points cannot disagree about the rules |
| SQLite for local state | single node; the zone itself stays in Fornex, the panel keeps only log, tokens and snapshots |

## Features

| Feature | Details |
|---|---|
| Zone view and edit | records with `id`, type, host, value, priority, TTL; write always through a plan |
| Four-step mail wizard | verification → MX/SPF → DKIM → DMARC, each step applied separately, blocked until the previous one is done |
| SPF merge | existing `include:`, `ip4:`, `a`, `mx` mechanisms survive; the preset adds, never drops |
| DMARC that is not weakened | `p=quarantine` or `p=reject` already in the zone is never lowered to `none` |
| DKIM normalisation | `p=…` alone gets the `v=DKIM1; k=rsa;` prefix; line breaks and spaces inside `p=` are removed |
| Confirmation by name | steps that replace a signing key or a verification string require typing the domain name; steps that only add records do not |
| Zone snapshot | BIND file plus JSON with record ids, stored with a sha256, before every change |
| DNS verification | public resolver plus `ns1.fornex.com`, so a cached answer is not mistaken for the truth |
| Token API | read-only by default, write only when issued with the flag; `X-CSRF-Token` for browser writes |
| CLI | 13 commands, dry run by default, `--apply` to write |
| Audit log | actor, action, target, dry run or not, result, request id |

## By the numbers

| What | Value | Where it comes from |
|---|---|---|
| PHP files | 45 | `Get-ChildItem -Recurse -Filter *.php` |
| Lines of PHP, JS, CSS, JSON, SQL | ~6 965 | the same tree, `Get-Content \| Measure-Object -Line` |
| API routes | 27 | `backend/config/routes.php` |
| CLI commands | 13 | `backend/src/Cli/Application.php` |
| Tests | 52 checks | `php tests/plan_test.php` |
| Runtime dependencies | 0 | no `composer.json`, no `package.json` |
| Presets | 4 (one of them a 4-step wizard) | `backend/config/presets.json` |

## How it works

```text
browser  ──►  index.php  ──►  Router  ──►  Controller  ──►  Service  ──►  Fornex API
   │                                        │                 │
   │  SPA (assets/js/app.js, no build)      │                 └── ZonePlanner (the rules)
   └───────────────────────────────────────┴──► SQLite: audit, tokens, snapshots

CLI  ──►  backend/bin/fornex  ──►  Cli\Application  ──►  the same Services
```

The zone is never a local copy: it is read from the Fornex API on each request, so what the panel
shows is what the API returns. Local state is only the audit log, API tokens, login attempts and
snapshots.

## Requirements

- PHP 8.5 with `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite`. Only 8.5 has been executed on this tree; the CI matrix runs 8.5.
- A Fornex account with a Public API key (`ru.fornex.com` → "My" → API). Keys issued for the panel itself are not needed.
- Any web server that can run PHP (nginx + PHP-FPM is what the project was developed against; `php -S` is enough to look at it).

## Installation

```bash
# 1. Files — outside or inside the web root, but the config never inside it
git clone https://github.com/paulmann/fornex-control.git /srv/fornex-control

# 2. Configuration one level above the application
install -m 640 /srv/fornex-control/.env.example /srv/fornex.env
$EDITOR /srv/fornex.env      # FORNEX_API_KEY, ADMIN_PASSWORD_HASH
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"

# 3. Local state (SQLite, log, snapshots) — created on first run, must be writable
install -d -m 750 /srv/fornex-control/var /srv/fornex-control/var/log /srv/fornex-control/var/snapshots
chown -R www-data:www-data /srv/fornex-control/var

# 4. Check before exposing it
php backend/bin/fornex help
php tests/plan_test.php
```

nginx (the panel lives in a sub-path here; `APP_BASE=/fornex` in that case):

```nginx
location ^~ /fornex/ {
    alias /srv/fornex-control/;
    index index.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }

    location ~ ^/fornex/(backend|tests|tools|var)/ { deny all; }
    location ~ /\. { deny all; }
}
```

- `docs/DEPLOY.md` — the full recipe, including the `Authorization` header and CSRF notes.
- `docs/TROUBLESHOOTING.md` — what each symptom means.

## Quick start

```bash
# a throwaway instance on localhost, no web server needed
cp .env.example .env                    # FORNEX_API_KEY=..., DB_PATH=var/fornex.sqlite
php -S 127.0.0.1:8080 index.php         # http://127.0.0.1:8080/

# no key at hand? the CLI is readable without one for help and presets
php backend/bin/fornex preset:list
```

## Configuration

Every key, its default and its effect: [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md). The four that matter most:

| Key | Default | Effect |
|---|---|---|
| `FORNEX_API_KEY` | — | the panel does not start without it |
| `ADMIN_PASSWORD_HASH` | empty | empty hash = login refused; generate with `password_hash()` |
| `AUTO_LOGIN_IP` | empty | password-less entry from one address; leave empty unless the network is yours |
| `DB_PATH`, `LOG_PATH`, `SNAPSHOT_DIR` | `var/…` | relative paths are resolved from the application root |

Secrets never reach the browser: the interface receives the CSRF token only. The API key and the
password hash are not returned by any route — `GET /api/meta` shows the API base URL, timeouts and
record types.

## Command line

```bash
php backend/bin/fornex domains                       # zones in the account
php backend/bin/fornex zone:show example.com         # records
php backend/bin/fornex zone:snapshot example.com     # snapshot, no writes
php backend/bin/fornex preset:apply example.com google-workspace --param:dkim='v=DKIM1; k=rsa; p=…'
php backend/bin/fornex preset:apply example.com google-workspace --param:dkim='v=DKIM1; k=rsa; p=…' --apply
php backend/bin/fornex verify example.com            # NS, MX, SPF, DKIM, DMARC, PTR
php backend/bin/fornex ns:status example.com         # delegation and the registrar instruction
```

Changing commands are dry runs: they print the plan and exit `0` without writing. `--apply` performs the change, and the snapshot is taken first.

## Mail presets

`google-workspace` is a four-step wizard because Google hands out the DKIM key only after the domain is verified:

| Step | What it writes | Confirmation | Blocked by |
|---|---|---|---|
| 1. Verification | TXT `@` `google-site-verification=…` | by domain name | — |
| 2. Mail | MX 1, MX 5, MX 10 (`SMTP.GOOGLE.COM`), SPF | none | step 1 |
| 3. DKIM | TXT `google._domainkey` | by domain name | step 1, and the key itself |
| 4. DMARC | TXT `_dmarc` | none | — |

Step 2 carries three MX records with the same target on purpose: Fornex rejects a second MX with an
identical value string, so the priority-5 and priority-10 records differ by a trailing dot and
letter case, which DNS treats as the same host.

## Safety rules

1. **Preview, then apply.** `POST /api/domains/{domain}/preset/{preset}/preview` is a read-only route; the interface never writes straight from a form.
2. **Snapshot before a write.** A BIND file and a JSON with record ids land in `SNAPSHOT_DIR` with a sha256; the audit entry names the snapshot.
3. **Delete by `id`.** No masks, no "all TXT with this prefix".
4. **`SOA` and `NS` are not editable.** They hold the zone.
5. **A missing value is not a refusal.** A step with an empty field returns `200` with "what is missing", not an error; only `blocked` disables the button.
6. **Refusals are explained.** A `403` from the Fornex API about creating zones is shown as a ticket text to send to support, not as a generic failure.
7. **Secrets by name only.** Reports, logs and the interface show a name, a mask and a fingerprint — never the value.

## Limits that come from Fornex

| Limit | What the panel does |
|---|---|
| Creating a zone through the API returns `403` on accounts where the right is disabled | shows the cabinet link and a ready-to-send ticket text; records, presets and checks keep working once the zone exists |
| A second MX with the same value string is rejected (`400 … already exists`), priority is not considered | the preset uses a trailing dot and letter case for the extra MX |
| Requests without a browser `User-Agent` hang on TLS | the client always sends one |
| The zone is rebuilt for minutes and resolvers cache the answer | verification resolves against `ns1.fornex.com`, not the cache |
| `NS` changes are possible only for domains ordered at Fornex | the panel prints the registrar instruction otherwise |

## Documentation

| Document | Contents |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | kernel, services, request flow, where the rules live |
| [`docs/API.md`](docs/API.md) | every route, auth, CSRF, error shapes |
| [`docs/CLI.md`](docs/CLI.md) | every command and its keys |
| [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) | all environment keys with defaults |
| [`docs/DEPLOY.md`](docs/DEPLOY.md) | nginx, PHP-FPM, permissions, headers |
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | layout, tests, adding a preset |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | symptom → cause → action |
| [`AGENTS.md`](AGENTS.md) | the working agreement for agents editing this tree |

## Development

```bash
php tools/lint.php          # PHP syntax plus BOM/CRLF/final-newline for code files
php tools/lint.php --text    # the same for markdown and dotfiles
php tests/plan_test.php      # 52 checks, no network, no database
php tools/secret-scan.php    # fails on a credential-shaped string
```

The test suite covers exactly the rules whose failure is silent: a repeated preset must not pile up
duplicate SPF/DMARC/DKIM, an MX to the same target with another priority must be updated rather than
created twice, SPF merge must keep a third-party `ip4:`, DMARC must not be weakened, and each wizard
step must report `done`/`todo`/`blocked`/`empty` correctly.

## FAQ

**Does it work without an API key?** No — the panel refuses to start; the key is the only way to read a zone. `help` and `preset:list` still run so you can install confidently.

**Can it create a zone?** Only if the Fornex account allows it. When the right is disabled the API answers `403`, and the panel explains what to send to support instead of failing silently.

**Where are the snapshots?** In `SNAPSHOT_DIR` (by default `var/snapshots/<domain>/`), a `.bind` file and a `.json` with record ids. They are not restored automatically: restoring a zone is a human decision made from the snapshot.

**Is the interface translated?** The interface is in Russian; the API, the CLI, the documentation and this README are in English.

## Contributing

Bug reports and patches are welcome — [`CONTRIBUTING.md`](CONTRIBUTING.md). Security issues:
[`SECURITY.md`](SECURITY.md), privately, not in an issue. Changes are recorded in
[`CHANGELOG.md`](CHANGELOG.md) in the Keep a Changelog format.

## License

MIT — see [`LICENSE`](LICENSE).
