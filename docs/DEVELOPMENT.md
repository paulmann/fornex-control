# Development

## Layout

```text
fornex-control/
  index.php                  front controller (also the `php -S` entry point)
  backend/
    autoload.php             PSR-4 style loader for FornexPanel\ → backend/src/
    bootstrap.php            settings → database → services → routes; the only wiring
    bin/fornex               CLI entry point, same services
    config/defaults.php      defaults, overridden by environment keys
    config/routes.php        the route table
    config/presets.json      preset and wizard definitions (data, not code)
    migrations/0001_init.sql SQLite schema for local state
    src/Kernel/              config, env, request/response, router, session, logger, app
    src/Fornex/              API client and its exception
    src/Service/             zone, planner, presets, verification, NS, tokens, audit
    src/Http/                controllers and the SPA shell
    src/Security/            auth and CSRF
    src/Cli/                 the command line
    src/Support/             JSON and small string helpers
  assets/                    css/js, no build step
  tests/plan_test.php        the whole suite, no network and no database
  tools/lint.php             PHP syntax plus BOM/CRLF/final newline
  tools/secret-scan.php      credential-shaped strings
  docs/                      this documentation
  wiki/                      sources of the GitHub wiki
```

## The loop

```bash
php tools/lint.php
php tests/plan_test.php
php tools/secret-scan.php
php -S 127.0.0.1:8080 index.php      # look at the interface
```

The test suite runs in under a second and needs no fixtures: the planner is a pure function
over a list of existing records and a list of planned ones.

## Where a change belongs

| Change | File |
|---|---|
| A rule about duplicate records, SPF merge, DMARC policy | `backend/src/Service/ZonePlanner.php` |
| A preset or a wizard step | `backend/config/presets.json`, then `PresetService` if a new field type is needed |
| A new HTTP endpoint | `backend/config/routes.php` + a controller in `backend/src/Http/Controller/` |
| A new CLI command | `backend/src/Cli/Application.php` + a section in `docs/CLI.md` |
| Interface behaviour | `assets/js/app.js` (one file, hash routes, no framework) |
| A setting | `backend/config/defaults.php`, `Config::ENV_MAP`, `.env.example`, `docs/CONFIGURATION.md` |

## Adding a preset

1. Write the entry in `presets.json`. A preset is either flat (`records` and `params`) or a
   wizard (`steps`, each with `params`, `records`, optional `requires`, `requires_param`,
   `present_if`, `confirm`).
2. Placeholders in a record value: `{domain}`, `{default_ip}`, `{param_key}`.
3. `present_if` turns "the record is already in the zone" into status `done`.
4. `confirm: true` only where a value is replaced (a signing key, a verification string).
5. Add cases to `tests/plan_test.php`: empty zone, second run, and a zone that already has a
   foreign `ip4:` and a stricter DMARC policy.

## Testing rules that fail silently

The suite exists because these mistakes produce no error at the moment they are made:

- a repeated preset that creates a second SPF (`permerror` for the whole domain);
- a second MX on the same target that moves half the mail away;
- an SPF merge that drops a third-party `ip4:` or `include:`;
- a DMARC update that lowers `p=quarantine` to `none`;
- a DKIM value pasted in fragments that never becomes a valid record;
- a wizard step whose status is wrong, so the interface disables the button that should work.

Each of them has a named check in the output (`[ok]` / `[FAIL]` with a reason), so a regression
names itself.

## Conventions

- PHP 8.5, `declare(strict_types=1)`, `final` classes, one class per file, namespaces mirror
  the directory tree.
- Code comments and interface text are Russian; identifiers, commits, documentation and
  README are English.
- No dependencies. A library that parses markdown or speaks HTTP is not worth the update
  chain for this tree size.
- Files: UTF-8 without BOM, LF, final newline. `tools/lint.php` enforces what `.gitattributes`
  declares.

## Release

1. Update `CHANGELOG.md` (`[Unreleased]` → `## [X.Y.Z] - YYYY-MM-DD`, comparison links).
2. Bump `app.version` in `backend/config/defaults.php` and `APP_VERSION` in `.env.example`
   (the version also busts the browser cache for `app.js`).
3. Run the four checks, plus a live smoke against a zone you own.
4. Commit, tag `vX.Y.Z`, push the tag, publish a GitHub release with the changelog section.
