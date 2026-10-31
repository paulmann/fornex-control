# Troubleshooting

Each entry: symptom → cause → action. Everything here was observed on a live installation;
nothing is a guess about how DNS "usually" behaves.

## The panel does not start

| Symptom | Cause | Action |
|---|---|---|
| `Fornex Control: no configuration found` | no `fornex.env`, no `.env`, no `FORNEX_ENV`, and no `FORNEX_API_KEY` in the environment | copy `.env.example` one level above the application root |
| `FORNEX_API_KEY is not set in .env` | the file is found but the key line is empty | paste the key from the Fornex cabinet |
| 500 with no body, log says "permission denied" | `var/` is not writable by the PHP user | `chown -R www-data:www-data var` |
| Login answers `bad_credentials` although the password is right | `ADMIN_PASSWORD_HASH` is empty or was generated with a trailing newline pasted in | regenerate with `php -r 'echo password_hash(...);'` and paste without the newline |

## The interface looks broken after an update

| Symptom | Cause | Action |
|---|---|---|
| Old layout, old code, old text | browser cache: `app.js` was served from cache | static links carry `?v=<version>`; bump `APP_VERSION` so the URL changes |
| White page, console shows 404 for `assets/…` | `APP_BASE` does not match the sub-path | set `APP_BASE=/fornex` (or empty in the site root) |
| A modal appears in the corner and closes on any click | the dialog was a sibling of `.modal-backdrop`, not its child | fixed in 1.1.0; the dialog is now inside the backdrop and closes on `mousedown` outside itself |
| The apply button does nothing and says nothing | a disabled button explains nothing | the button is `aria-disabled`; a click reports what is missing |

## Zones and records

| Symptom | Cause | Action |
|---|---|---|
| `403 You do not have permission to perform this action` on zone creation | the Fornex account has the "create zones via API" right disabled | create the zone in the cabinet; the panel shows a ready ticket text |
| `400 … already exists` when adding a second MX | Fornex compares the value string and ignores the priority | make the value differ: trailing dot (`SMTP.GOOGLE.COM.`) or letter case |
| A record is not in the zone although the panel says it was created | the zone is rebuilt for minutes; the public resolver still has the old answer | ask `ns1.fornex.com` (what the verification does) instead of a public resolver |
| Two SPF records in a zone → mail fails with `permerror` | a preset was applied to a zone that already had an SPF and merging was bypassed | delete one by `id`, then apply the preset again — the merge keeps the mechanisms it does not know |
| DMARC stayed `p=none` although the preset said `quarantine` | the preset does not lower a policy, but it also cannot know when to raise one | set `p=quarantine` deliberately; the panel never raises it silently |
| DKIM check fails while the record is visibly there | the value was pasted in fragments and lost the `v=DKIM1; k=rsa;` prefix or gained a line break | paste the whole string; normalisation adds the prefix and strips spaces, but a truncated key stays truncated |
| `405 method_not_allowed` | the path exists, the method does not | the response names the allowed methods in `Allow` and in `details.allowed` |
| `409` on apply | the step requires the domain name as confirmation | send `"confirm": "example.com"` — for DKIM and verification steps only |

## API client

| Symptom | Cause | Action |
|---|---|---|
| Requests hang with no response at all | no browser-like `User-Agent`; Fornex stalls the TLS handshake | `FORNEX_USER_AGENT` must be set (it is by default) |
| `502 upstream_error` on everything | wrong host in `FORNEX_BASE_URL` | use `https://ru.fornex.com/api`; `fornex.com/api/` answers `302` or `403` |
| Answers take 30 s and then fail | the network drops or the API is slow | raise `FORNEX_TIMEOUT`; retries apply to network and 5xx only |
| The interface shows a version older than the code | `APP_VERSION` was not bumped | bump it; it is what `?v=` is built from |

## Verification report

| Symptom | Cause | Action |
|---|---|---|
| "MX not found" although the zone has it | one resolver query timed out | re-run; the report queries a public resolver and `ns1.fornex.com` separately |
| NS "not delegated" for a domain that works | NS were changed at the registrar minutes ago | wait for the registry TTL, then re-check |
| PTR mismatch | the PTR record belongs to the address owner, not to this zone | ask the provider of the address to set it |

## Nothing helps

```bash
php tools/lint.php
php tests/plan_test.php
php backend/bin/fornex help          # proves configuration and database
curl -s https://panel.example.com/fornex/api/health
tail -n 50 var/log/app.log
```

`var/log/app.log` records every failed operation with its reason; the `request_id` in the log
line matches the one in the audit entry. Open an issue with the log line, the route or
command, and the zone state — never with the API key.
