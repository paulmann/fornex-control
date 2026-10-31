# HTTP API

Base path: whatever the application serves (`APP_BASE`, empty when it is in the site root).
All endpoints answer JSON. Responses carry `Cache-Control: no-store`.

## Envelope

```json
{"ok": true, "…": "route-specific fields", "request": "/api/domains"}
```

```json
{"ok": false, "error": {"code": "invalid_request", "message": "…", "details": {}}}
```

`details` appears only when the handler has something to add (allowed methods, the nearest
existing record, the missing parameter).

## Errors

| Status | `code` | When |
|---|---|---|
| 401 | `unauthorized`, `bad_credentials` | no session, no token, wrong password |
| 403 | `forbidden` | read-only token on a write route |
| 404 | `not_found` | no such route, or the domain is not in the account |
| 405 | `method_not_allowed` | the path exists, the method does not (`Allow` header set) |
| 409 | `conflict` | a step requires confirmation that was not given |
| 422 | `invalid_request` | bad domain name, bad record id, bad record fields |
| 429 | `rate_limited` | too many login attempts from one address |
| 502 | `upstream_error` | the Fornex API failed or answered with an error |
| 500 | `internal_error` | unexpected; the message is generic in production |

## Authentication

Two mechanisms, both checked by `Auth::requireUser`:

- **Session.** `POST /api/login` with `{"login": "…", "password": "…"}`. The cookie is
  `HttpOnly`, `SameSite=Lax`, `Secure` when `SESSION_SECURE=1`. `AUTO_LOGIN_IP` grants a
  session from one trusted address without a password.
- **Bearer token.** `Authorization: Bearer <token>` on any route. A token is read-only
  unless it was issued with the write flag; a write with a read-only token is `403`.

Both entry points of the panel (browser and script) therefore use the same routes.

## CSRF

Write routes require `X-CSRF-Token` when the session is used. The token is issued by
`GET /api/csrf` and embedded in the SPA shell. Token requests do not need it: a script has no
browser session to hijack.

## Routes

`auth` — needs a session or a token. `csrf` — needs `X-CSRF-Token` for a session request.
`write` — refused for a read-only token.

| Method | Path | auth | csrf | write | Meaning |
|---|---|---|---|---|---|
| GET | `/api/csrf` | no | no | no | issue a CSRF token |
| POST | `/api/login` | no | no | yes | create a session |
| POST | `/api/logout` | yes | yes | yes | destroy the session |
| GET | `/api/me` | yes | no | no | who is logged in, session state |
| GET | `/api/health` | no | no | no | `status`, `version`, `php`, `time` |
| GET | `/api/meta` | yes | no | no | app name and version, API base URL, default IP, record types |
| GET | `/api/domains` | yes | no | no | zones in the account |
| POST | `/api/domains` | yes | yes | yes | create a zone (Fornex may answer 403) |
| GET | `/api/domains/{domain}` | yes | no | no | one zone with its records |
| DELETE | `/api/domains/{domain}` | yes | yes | yes | delete a zone (`dry_run` supported) |
| GET | `/api/domains/{domain}/zone` | yes | no | no | records |
| POST | `/api/domains/{domain}/zone` | yes | yes | yes | add a record |
| PUT | `/api/domains/{domain}/zone/{id}` | yes | yes | yes | update a record by `id` |
| DELETE | `/api/domains/{domain}/zone/{id}` | yes | yes | yes | delete a record by `id` |
| POST | `/api/domains/{domain}/snapshot` | yes | yes | yes | take a snapshot now |
| GET | `/api/domains/{domain}/zone-file` | yes | no | no | the zone as a BIND file |
| GET | `/api/presets` | yes | no | no | preset catalogue |
| GET | `/api/domains/{domain}/wizard/{preset}` | yes | no | no | per-step status (`done`/`todo`/`empty`/`blocked`) |
| POST | `/api/domains/{domain}/preset/{preset}/preview` | yes | yes | **no** | the plan; nothing is written |
| POST | `/api/domains/{domain}/preset/{preset}/apply` | yes | yes | yes | perform the plan (confirmation may be required) |
| GET | `/api/domains/{domain}/verify` | yes | no | no | DNS report |
| GET | `/api/domains/{domain}/ns` | yes | no | no | delegation status and instruction |
| POST | `/api/domains/{domain}/ns` | yes | yes | yes | change NS (`dry_run` supported) |
| GET | `/api/audit` | yes | no | no | audit entries (`?limit=`, `?domain=`) |
| GET | `/api/tokens` | yes | no | no | issued tokens (prefix and metadata, never the value) |
| POST | `/api/tokens` | yes | yes | yes | issue a token |
| DELETE | `/api/tokens/{id}` | yes | yes | yes | revoke a token |

## Examples

```bash
# read a zone with a token
curl -s -H "Authorization: Bearer $TOKEN" https://panel.example.com/api/domains/example.com/zone

# plan a preset step — writes nothing
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"params":{"dkim":"v=DKIM1; k=rsa; p=MIIB…"}}' \
     https://panel.example.com/api/domains/example.com/preset/google-workspace/preview?step=dkim

# apply it; the DKIM step requires the domain name as confirmation
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"params":{"dkim":"v=DKIM1; k=rsa; p=MIIB…"},"confirm":"example.com"}' \
     https://panel.example.com/api/domains/example.com/preset/google-workspace/apply?step=dkim
```

`dry_run: true` in a body is accepted by the writing endpoints and answers with the plan
only, which is how the interface shows "what will happen" without a second route.

## What is never returned

The Fornex API key, the password hash, an issued token after its creation moment, and any
value of a secret. `GET /api/tokens` shows a prefix and metadata; the full token appears once,
in the response to `POST /api/tokens`.
