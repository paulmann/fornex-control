# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Wiki published at <https://github.com/paulmann/fornex-control/wiki>: 13 pages in Russian
  (why it exists, installation, configuration, architecture, API, CLI, mail presets, security,
  operations, troubleshooting, development, FAQ) with a generated sidebar and footer; sources
  live in `wiki/` and are built with `ghagent wiki build`.

### Changed

- README: the summary paragraph now sits directly after the H1 and the language switcher
  moved below it, so tooling that reads the first paragraph (`ghagent meta suggest`) sees the
  product summary instead of the `md-i18n` comment.

## [1.1.0] - 2026-11-01

First public release. The tree published here is the code that had been running as a
private panel (internal versions 1.0.0 → 1.1.0), with installation-specific values
removed: host names, addresses, absolute paths and the API key placeholder.

### Added

- **Four-step mail wizard** for Google Workspace: `verify` → `mail` → `dkim` → `dmarc`.
  Each step is applied on its own, shows the plan for that step, and stays blocked until
  the previous step is done (`PresetService::stepsOverview`, route
  `GET /api/domains/{domain}/wizard/{preset}`).
- **Confirmation by domain name only where data is replaced**: a step declares `confirm`,
  so the DKIM key and the verification string ask for the name while steps that only add
  records do not.
- **SPF merge**: `ZonePlanner::mergeSpf` keeps existing `include:`, `ip4:`, `a`, `mx`
  mechanisms and adds the ones the preset brings; an unchanged result becomes a skip, not
  an update.
- **DMARC that cannot be weakened**: `p=quarantine` and `p=reject` already in the zone are
  never lowered to `none`; the preset only adds `rua`/`pct` and missing tags.
- **DKIM normalisation**: a bare `p=…` value receives the `v=DKIM1; k=rsa;` prefix, line
  breaks collapse and spaces inside `p=` are removed, so a key pasted in fragments still
  produces a valid record.
- **Third MX for Google** (priority 10) in the mail step, using a trailing dot and letter
  case because Fornex rejects a second record with an identical value string.
- **Zone snapshot before every write**: BIND file plus JSON with record ids and a sha256,
  recorded in `SNAPSHOT_DIR` and named by the audit entry.
- **Audit log** with actor, action, target, dry-run flag, result and request id; `GET /api/audit`.
- **API tokens**: read-only by default, write only when issued with the flag, `X-CSRF-Token`
  required for browser writes; the preset preview route works for a read-only token.
- **CLI** with 13 commands; every changing command is a dry run unless `--apply` is given.
- **DNS verification** against a public resolver and `ns1.fornex.com` in one report: NS,
  MX and their targets, SPF, DKIM by four selectors, DMARC, A for `@`/`www`, PTR.
- **Tooling**: `tools/lint.php` (PHP syntax plus BOM/CRLF/final newline), `tools/secret-scan.php`,
  `tests/plan_test.php` with 52 checks, GitHub Actions CI.

### Changed

- Default configuration is installation-neutral: relative state paths (`var/…`), no site
  address baked into presets, `UTC` time zone, no hard-coded URL base.
- The panel starts from environment variables when no configuration file is present
  (containers, CI), and reports a missing `FORNEX_API_KEY` instead of failing later.
- A step with an empty field returns `200` with "what is missing" rather than an error;
  only a blocked step disables the preview button.

### Removed

- Installation-specific identifiers: host names, public and private addresses, absolute
  paths of the original deployment, and the sample API key from `.env.example`.

### Fixed

- Modal windows were positioned outside the backdrop; the dialog is now a child of the
  backdrop and closes on `mousedown` outside itself, so selecting text inside does not
  close it.
- The confirm field's placeholder equalled the domain name, so the field looked filled and
  the apply button silently stayed disabled; the button is now `aria-disabled` and a click
  explains what is missing.
- Static assets carry `?v=<version>`, so a browser does not keep the previous `app.js`
  after an upgrade.
- The audit view printed an installation-specific snapshot path; it now names `SNAPSHOT_DIR`.

[Unreleased]: https://github.com/paulmann/fornex-control/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/paulmann/fornex-control/releases/tag/v1.1.0
