# Architecture

## Layers

```text
index.php ─ Router ─ Controller ─ Service ─ Fornex API
                       │            │
                       │            └── ZonePlanner  (the rules about records)
                       │            └── DnsResolver  (verification)
                       │
                       └── View (SPA shell)     SQLite: audit_events, snapshots,
                                                    api_tokens, login_attempts

backend/bin/fornex ─ Cli\Application ─ the same Services
```

The HTTP front controller and the CLI both call `backend/bootstrap.php`, which returns an
`App` with a `Services` container. There is no second wiring and no second copy of a rule:
a preset applied from the interface, from a script or from `php backend/bin/fornex` goes
through `PresetService` → `ZonePlanner` → `ZoneService`.

## Kernel

| Class | Responsibility |
|---|---|
| `Kernel\Env` | reads `KEY=VALUE` from a file; a real environment variable wins over the file |
| `Kernel\Config` | immutable settings tree, env overrides by a fixed map, `path()` resolves relative paths from the application root |
| `Kernel\Request` | method, path, query, JSON body, bearer token, client address |
| `Kernel\Response` | `{ok:true, …}` / `{ok:false, error:{code, message, details}}`, JSON, HTML, text |
| `Kernel\Router` | table-driven match, `{name}` placeholders, 404 vs 405 distinction |
| `Kernel\Session` | cookie session, idle timeout, regeneration on login |
| `Kernel\Logger` | rotating file log, never receives a secret |
| `Kernel\App` | runs the route, applies auth/CSRF/write flags, converts exceptions to responses |

## Services

| Service | Responsibility |
|---|---|
| `Fornex\Client` | HTTP to `ru.fornex.com/api`, browser `User-Agent`, retries on network and 5xx, `FornexException` with code and message |
| `Service\ZoneService` | reads a zone, takes a snapshot before every write, performs add/update/delete by `id` |
| `Service\ZonePlanner` | the rules: duplicate detection, SPF merge, DMARC policy, plan shape (`create`, `update`, `skip`, `conflict`) |
| `Service\PresetService` | preset definitions, wizard steps, field filtering per step, DKIM normalisation, `stepsOverview` status |
| `Service\VerifyService` | NS, MX and targets, SPF, DKIM by four selectors, DMARC, A `@`/`www`, PTR, one report |
| `Service\NsService` | delegation status, registrar instruction, NS change when the domain is ordered at Fornex |
| `Service\TokenService` | issues tokens (prefix + hash), verifies, revokes; read-only unless issued with the flag |
| `Service\AuditService` | writes and reads `audit_events` |
| `Database\Database` | SQLite with WAL-ish settings, migrations from `backend/migrations` |

## The plan

Every write path starts with a plan:

```json
{
  "create":   [{"host": "@", "type": "MX", "value": "SMTP.GOOGLE.COM", "prio": 1, "why": "…"}],
  "update":   [{"id": 4237354, "from": "…", "to": "…", "why": "…"}],
  "skip":     [{"id": 4237363, "reason": "already present"}],
  "conflict": [{"id": 4232119, "reason": "MX to a local mail host competes with Google"}]
}
```

`preview` returns the plan and writes nothing. `apply` recomputes the plan against the
current zone and then performs `create` and `update`; `skip` and `conflict` are never
applied automatically. A snapshot is taken between the two steps, so the plan the user saw
and the snapshot on disk describe the same zone.

## Wizard

`PresetService::stepsOverview` computes, for one domain, each step's status:

| Status | Meaning | UI |
|---|---|---|
| `done` | the record the step writes is already in the zone (`present_if`) | shows "already present" |
| `todo` | the step can be applied | active |
| `empty` | the step needs a value that is not filled in yet | active, server answers with "what is missing" |
| `blocked` | a `requires` step is not done | preview disabled, the missing step is named |

`empty` is deliberately not a refusal: the DKIM step is `empty` until a key is pasted, and
disabling the button there made the step impossible to start.

## State

SQLite holds only what the zone cannot: `audit_events` (who, what, dry run or not, result),
`snapshots` (path, sha256, entry count), `api_tokens` (prefix and hash, never the token),
`login_attempts` (rate limiting). The zone itself is read from the API on every request, so
the panel can never show a stale copy of it.

## Verification

`VerifyService` asks two servers per record type: a public resolver (`VERIFY_RESOLVER`) and
Fornex's authoritative one (`VERIFY_AUTHORITATIVE`). The report distinguishes "the record is
missing" from "the resolver has not caught up yet", which is the difference between a defect
and a five-minute wait after a zone change.
