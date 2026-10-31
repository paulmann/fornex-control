# Contributing

Thanks for looking at the code. This panel edits DNS zones, and a wrong write is not
visible until mail stops arriving, so the rules below exist to keep the review cheap.

## Before you start

- **Bugs and ideas** — open an issue with the command you ran and the output you got.
  For a zone problem, include the record type and the zone state (`zone` output), never a
  real API key.
- **Security issues** — not in an issue; see [`SECURITY.md`](SECURITY.md).

## Ground rules

1. **Zero runtime dependencies.** No `composer.json`, no `package.json`. The tree must run
   from `git clone` plus PHP 8.5. Tooling goes into `tools/` as plain PHP.
2. **The rules live in one place.** Planning, merging and normalisation belong to
   `backend/src/Service/ZonePlanner.php` and `PresetService.php`. A controller or the
   interface may not decide on its own that "this record is a duplicate".
3. **Preview before write.** A new write path must be reachable through a plan first. A
   route that writes without a preview needs a reason in the pull request.
4. **Delete by `id`.** No masks, no prefix matching, no "all records of type X".
5. **`SOA` and `NS` are read-only.** They hold the zone.
6. **Secrets never leave the server.** Print a name, a mask and a fingerprint; never a
   value. The API key and the password hash are not returned by any route.
7. **A refusal must be explained.** If a Fornex limit blocks an action, the interface says
   what to do — a ready ticket text, a registrar instruction — not "error 403".

## Local checks

Every pull request must pass the same four commands CI runs:

```bash
php tools/lint.php            # PHP syntax, BOM, CRLF, final newline
php tools/lint.php --text     # the same for markdown and dotfiles
php tests/plan_test.php       # 52 checks, no network, no database
php tools/secret-scan.php     # fails on a credential-shaped string
```

A new rule that can fail silently needs a case in `tests/plan_test.php`. A rule without a
test is a rule that will be broken by an unrelated change.

## Style

- PHP 8.5, `declare(strict_types=1)`, one class per file, `final` unless inheritance is
  designed. Namespaces follow the directory tree under `FornexPanel\`.
- Comments and user-facing interface text are Russian; code identifiers, commit messages,
  documentation and README are English. Keep it that way in the same change.
- The interface is one file without a build step (`assets/js/app.js`); no bundler, no
  framework, no CDN.
- Files are UTF-8 without BOM, LF line endings, final newline. `.gitattributes` enforces it;
  `tools/lint.php` verifies it.

## Adding a preset

1. Describe it in `backend/config/presets.json`: `title`, `summary`, `applies_to`, `params`,
   `records`, and `steps` when the provider hands out values in an order (Google does).
2. Use `present_if` when a record "already exists" should count as done, `requires` when a
   step must wait for another, `requires_param` when a value is mandatory.
3. Set `confirm: true` only for steps that replace a signing key or a verification string.
4. Add a case to `tests/plan_test.php`: apply the preset to an empty zone, apply it twice,
   and apply it to a zone that already has a foreign `ip4:` and a stricter DMARC policy.
5. Run the wizard against a zone you own before opening the pull request, and paste the plan
   (not the applied result) into the description.

## Commit messages

`type: summary` in the imperative mood (`fix: keep ip4 mechanisms when merging SPF`), one
logical change per commit, body explains *why* when it is not obvious. The changelog is
updated in the same pull request for anything user-visible.

## Documentation

A behaviour change updates, in one commit: the code, `README.md` and `README.ru.md` when the
public description changes, `docs/` for the affected subsystem, `CHANGELOG.md` under
`[Unreleased]`, and `wiki/` when a page describes the old behaviour.
