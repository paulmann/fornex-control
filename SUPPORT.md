# Support

## Where to ask

| Question | Where |
|---|---|
| "It does not work, here is the error" | [Issues](https://github.com/paulmann/fornex-control/issues) — use the bug template |
| "How do I configure X?" | [Wiki](https://github.com/paulmann/fornex-control/wiki), then `docs/CONFIGURATION.md`; if the answer is missing, open an issue — the documentation is part of the product |
| "Would you accept a feature?" | open an issue first, describe the use case; a preset or a rule is easier to accept than a subsystem |
| "Is this a security problem?" | **not in an issue** — [SECURITY.md](SECURITY.md) |
| "The Fornex API answers 403/400" | `docs/TROUBLESHOOTING.md`; those are limits of the Fornex side, not of the panel |

## What to include in a bug report

1. What you ran (command or route) and what you expected.
2. What happened: the response, the log line from `var/log/app.log`, the audit entry if the
   panel was involved.
3. Version: the answer of `GET /api/health`.
4. Environment: PHP version, web server, whether the configuration file is outside the root.
5. The zone state (`zone:show <domain>`) with the domain and values replaced by
   `example.com` / `203.0.113.10`.

Never include the API key, a password hash, a private key or a real client zone.

## What is not supported

- Installing on PHP older than 8.5.
- A panel exposed to the open internet without an address allow-list.
- Recovering a zone without a snapshot: the panel snapshots before every write, but restoring
  is a human decision made from that snapshot.
- Changes to zones you do not own.
