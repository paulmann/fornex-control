# Documentation index

Start with the README, which is enough to install and use the panel. Come here when you need
detail about one subsystem.

| Document | Read it when |
|---|---|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | you need to know where a rule lives, or how a request travels from `index.php` to the Fornex API |
| [`API.md`](API.md) | a script has to read or change a zone over HTTP, or you need the error shapes |
| [`CLI.md`](CLI.md) | you are writing a cron job, a hook or a one-off operation |
| [`CONFIGURATION.md`](CONFIGURATION.md) | you are deploying, or a setting does not do what you expected |
| [`DEPLOY.md`](DEPLOY.md) | nginx, PHP-FPM, permissions, access control, backup, update |
| [`DEVELOPMENT.md`](DEVELOPMENT.md) | you are changing the code, adding a preset or a rule |
| [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) | something does not work and you want the cause, not a guess |

Long-form guides also live in the [wiki](https://github.com/paulmann/fornex-control/wiki); the
`wiki/` directory in this repository holds their sources.

## The one rule that explains the rest

Every write starts as a **plan** that anyone can read before anything changes, and a **snapshot**
is taken before the plan runs. If a document here seems to describe something else, the plan
wins — `docs/ARCHITECTURE.md` explains its shape.
