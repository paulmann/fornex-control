# Command line

Entry point: `php backend/bin/fornex <command> [options]`.

Configuration is the same file the panel uses (`FORNEX_ENV` → `.env` → `../fornex.env`);
without a file the CLI reads the environment. A missing `FORNEX_API_KEY` exits with code `2`
and explains where the key belongs.

## Safety model

Every changing command is a **dry run by default**: it prints the plan, writes nothing and
exits `0`. Writing needs `--apply`. This is the same rule as in the interface (preview →
apply), and it makes the CLI safe to call from a cron job or a monitoring hook by accident.

## Global options

| Option | Meaning |
|---|---|
| `--apply` | perform the change (without it: plan only) |
| `--json` | machine-readable output on stdout |
| `--param:key=value` | a preset parameter, repeatable |
| `--note="…"` | note stored with the snapshot and the audit entry |

## Commands

| Command | Keys | What it does |
|---|---|---|
| `help` | — | the command list |
| `domains` | — | zones in the account: name, record count, status |
| `zone:show <domain>` | `--json` | records as a table or JSON |
| `zone:snapshot <domain>` | `--note` | write a snapshot to `SNAPSHOT_DIR` and print its path and sha256 |
| `entry:add <domain>` | `--host --type --value [--prio] [--ttl] [--apply]` | add one record |
| `entry:update <domain>` | `--id --host --type --value [--prio] [--ttl] [--apply]` | change the record with that `id` |
| `entry:delete <domain>` | `--id [--apply]` | delete by numeric `id` |
| `preset:list` | — | presets from `backend/config/presets.json` |
| `preset:apply <domain> <preset>` | `--param:…` `--dkim-selector` `--mail-host` `--apply` | plan or apply a preset |
| `verify <domain>` | `--json` | DNS report: NS, MX and targets, SPF, DKIM, DMARC, A `@`/`www`, PTR |
| `ns:status <domain>` | `--json` | delegation status and the registrar instruction |
| `ns:apply <domain>` | `--ns a,b,c` `--apply` | change NS (only for a domain ordered at Fornex) |
| `domain:add <domain>` | `--ip` `--apply` | create a zone (Fornex may answer 403) |
| `audit` | `--domain=` `--limit=30` `--json` | recent audit entries |

Exit codes: `0` success (including a dry run), `1` the operation failed or was refused,
`2` usage or configuration problem.

## Examples

```bash
# what is in the account
php backend/bin/fornex domains

# one record, planned then written
php backend/bin/fornex entry:add example.com --host www --type A --value 203.0.113.10 --ttl 300
php backend/bin/fornex entry:add example.com --host www --type A --value 203.0.113.10 --ttl 300 --apply

# the whole Google wizard, step by step
php backend/bin/fornex preset:apply example.com google-workspace \
    --param:verification='google-site-verification=…' --apply
php backend/bin/fornex preset:apply example.com google-workspace --apply
php backend/bin/fornex preset:apply example.com google-workspace \
    --param:dkim='v=DKIM1; k=rsa; p=MIIB…' --apply
php backend/bin/fornex preset:apply example.com google-workspace \
    --param:dmarc='v=DMARC1; p=none; rua=mailto:postmaster@example.com' --apply

# verification and audit
php backend/bin/fornex verify example.com
php backend/bin/fornex audit --domain=example.com --limit=20
```

`preset:apply` accepts a step only through the web API; on the command line a preset without
parameters plans every step it can build, which is why the wizard steps are applied one at a
time by passing the parameter that belongs to the step.

## Scripting

- `--json` is stable enough to parse: `{ok: true, …}` on success, `{ok: false, error: {…}}`
  on failure.
- The audit actor for CLI actions is `cli`, so a script's changes are distinguishable from a
  person's in the log.
- Snapshots are written before the change, the same as in the interface — a failed run still
  leaves a snapshot of the state it was about to change.
