# Security policy

This panel holds an API key that can rewrite DNS zones and a password hash for its own
login. It is small on purpose: no third-party runtime code, no database server, one
configuration file outside the web root.

## Reporting a vulnerability

Report privately through **GitHub → Security → Advisories → "Report a vulnerability"** on
this repository. Please do not open a public issue and do not include a working API key.

Useful in a report: the version (`GET /api/health` or `README`), the route or command, what
an attacker gains, and the smallest reproduction you have. You will get an answer within
seven days. If the issue is confirmed, a fix and a `CHANGELOG` entry are prepared before the
advisory is published, and the advisory names you unless you ask otherwise.

## Supported versions

| Version | Supported |
|---|---|
| 1.1.x | yes |
| < 1.1 | no (first public release is 1.1.0) |

## What is in scope

- Authentication bypass: reaching an authenticated route without a session or token, or
  escalating a read-only token to a write.
- CSRF: a write performed without `X-CSRF-Token` from a browser session.
- Injection into a zone: a value that turns into an extra record, a different host, or a
  different type than the plan showed.
- Secret disclosure: the API key or the password hash appearing in a response, a log line,
  an audit entry or an error message; the key appearing in the interface.
- Path traversal or arbitrary file read/write through the front controller, the static-file
  branch or snapshot handling.
- Denial of service that a single unauthenticated request can cause.

## What is out of scope (reported as a bug, not a vulnerability)

- **The trusted-address login.** `AUTO_LOGIN_IP` grants password-less access from one
  address by design; leaving it empty disables the feature. Using it on an untrusted network
  is a deployment mistake, documented in `docs/CONFIGURATION.md`.
- **The API key living in a file.** The panel reads it from a file outside the web root
  (`fornex.env`). A key readable by other local users is a permissions problem; the
  documentation asks for `640` and the owner set to the PHP user.
- **Fornex-side limits.** A `403` on zone creation, a rejected duplicate MX, or a zone that
  takes minutes to rebuild are properties of the API, described in `README` and
  `docs/TROUBLESHOOTING.md`.
- **Missing security headers on someone else's server.** The nginx recipe in
  `docs/DEPLOY.md` sets them; the application does not fight the server for them.
- **Rates and quotas of the Fornex API.** Not a vulnerability of this code.

## Hardening checklist for a deployment

1. Configuration outside the web root, `640`, owner = PHP user; `.env` never committed.
2. `SESSION_SECURE=1` behind HTTPS; the cookie is then `HttpOnly` and `SameSite=Lax`.
3. `AUTO_LOGIN_IP` empty unless the network is yours.
4. `php tools/secret-scan.php` clean before every deploy; the key is rotated in the Fornex
   cabinet, not in git history.
5. Deny `/backend/`, `/tests/`, `/tools/`, `/var/` and dotfiles in the web server; the
   panel does not serve them, but the web server must not either.
6. API tokens: read-only unless a script really writes; revoke rather than reuse.
