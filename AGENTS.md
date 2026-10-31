# AGENTS.md

Working agreement for an agent (or a human) editing this tree. It is a map, not a manual:
detail lives in `docs/`.

## What this project is

A self-hosted panel for Fornex DNS zones: PHP 8.5, zero dependencies, SQLite for local
state, one SPA without a build step, a CLI that shares the same services. The zone itself
stays in Fornex; the panel keeps a log, tokens and snapshots.

## Commands that must pass before anything is called done

```bash
php tools/lint.php            # PHP syntax + BOM/CRLF/final newline
php tools/lint.php --text     # the same for markdown and dotfiles
php tests/plan_test.php       # 52 checks, no network, no database
php tools/secret-scan.php     # no credential-shaped strings
php backend/bin/fornex help   # boot smoke: the app builds without a web server
```

A boot smoke needs configuration; without a file it reads the environment:

```bash
FORNEX_API_KEY=ci-dummy DB_PATH=var/ci.sqlite LOG_PATH=var/log/ci.log \
SNAPSHOT_DIR=var/snapshots php backend/bin/fornex help
```

## Where things are

| Question | File |
|---|---|
| The rules about duplicate records, SPF merge, DMARC policy | `backend/src/Service/ZonePlanner.php` |
| Preset definitions and wizard steps | `backend/config/presets.json`, `backend/src/Service/PresetService.php` |
| Routes and auth flags | `backend/config/routes.php` |
| Service wiring for HTTP and CLI | `backend/bootstrap.php` |
| Configuration defaults | `backend/config/defaults.php`, `.env.example` |
| Interface (single file, no build) | `assets/js/app.js`, `assets/css/fornex.css` |
| Tests | `tests/plan_test.php` |

## Non-negotiable rules

1. **Preview, then apply.** A new write path is reachable through a plan first.
2. **Snapshot before a write.** `ZoneService` takes it; do not bypass it.
3. **Delete by numeric `id`.** Never by mask or prefix.
4. **`SOA` and `NS` are read-only.**
5. **One planner.** The interface, the API and the CLI must not re-implement a rule.
6. **Secrets by name.** No value in a response, a log line, an audit entry or an error.
7. **Empty is not blocked.** A missing field is "what is missing" (`200`), not a refusal;
   only `blocked` disables an action.
8. **A refusal is explained.** Fornex limits become an instruction or a ticket text.
9. **Language split.** Interface text and code comments in Russian; identifiers, commits,
   documentation and README in English; `README.ru.md` updated with `README.md`.
10. **Honest numbers.** A figure in documentation names the command that produced it.

## Traps that cost time here

| Symptom | Cause | Action |
|---|---|---|
| The browser shows an old interface after a deploy | cached `app.js` | static links carry `?v=<version>`; bump `app.version` when the asset changes |
| A second MX is rejected with `400 … already exists` | Fornex compares the value string, not the priority | differ by trailing dot and letter case |
| Verification says a record is missing although it is in the zone | resolver cache, the zone is rebuilt for minutes | ask `ns1.fornex.com`, not the public resolver |
| Requests to the Fornex API hang with no answer | no `User-Agent` | the client always sends one |
| A modal appears in the corner and closes on click | the dialog was a sibling of the backdrop | the dialog is a child of `.modal-backdrop` |
| The apply button is disabled and nothing explains why | placeholder looked like a filled value | the button is `aria-disabled` and a click names the gap |

## What not to do

- Do not add a dependency, a bundler or a framework.
- Do not "fix" a refusal by retrying with a different shape; fix the rule that produced it.
- Do not delete a record to make a preset idempotent — merge or skip instead.
- Do not commit `var/`, `.env`, `fornex.env`, `*.sqlite` or a snapshot of a real zone.
- Do not report a check you did not run.
