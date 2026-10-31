# Pull request

## What changes

<!-- One paragraph: what was wrong and what the change does. -->

## Why

<!-- The symptom, the measurement or the ticket. A wrong rule in a DNS zone is silent, so name the failure it prevents. -->

## Checks

- [ ] `php tools/lint.php` — 0 findings
- [ ] `php tools/lint.php --text` — 0 findings
- [ ] `php tests/plan_test.php` — all checks pass
- [ ] `php tools/secret-scan.php` — 0 findings
- [ ] a new silent-failure rule has a case in `tests/plan_test.php`
- [ ] `CHANGELOG.md` has an entry under `[Unreleased]`
- [ ] `README.md` and `README.ru.md` agree when the public description changed
- [ ] the affected page in `docs/` or `wiki/` describes the new behaviour
- [ ] no secret, real domain or real address in the diff

## Risk

<!-- What can this change break on a live zone? Which preset, record type or step is affected? -->

## Plan output

<!-- For anything touching planning: paste the preview (not the applied result). -->
