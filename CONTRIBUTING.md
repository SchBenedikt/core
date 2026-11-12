# Contributing

Thanks for helping. This repository is the shared core of Aloha Social; the
rules below exist to keep it usable by both hosts.

## Setup

```sh
composer install
composer check
```

PHP 8.3+ and the extensions listed in the README. No database or network is
needed for the suite.

## Which repository owns the change?

Before writing code, decide where the behaviour belongs:

- **`core`** — social behaviour that both hosts need, protocol rules, shared
  schemas and the shared UI package under `ui/`.
- **`AlohaSocial/social`** — Nextcloud adapters, controllers, templates, admin
  settings and Nextcloud authentication integration.
- **`AlohaSocial/server`** — standalone bootstrap, accounts/auth, installer,
  container/systemd setup and server migrations.

If only one host needs it, keep it there. If both hosts need it, the core owns
the rule and each host owns its adapter. Do not hide a Nextcloud-only branch
in `src/`. Migrations are host-owned; see
[ADR 0004](docs/adr/0004-incremental-migration-and-ownership.md) and the
[migration guide](docs/guides/migration-from-nextcloud.md). Shared screens
use the [`HostBridge`](docs/guides/shared-ui.md), never a host global.

## Before you open a pull request

```sh
composer check        # lint + host-neutrality guard + links + tests
```

- New behaviour has a test that fails without it.
- A new port implementation passes the existing contract case.
- `src/` contains no host code (`OCP\`, `OCA\`, a `nextcloud/*` dependency).
- Documentation is updated when behaviour, a route or a limit changes.

## Commits

Conventional Commits with a scope:

```
feat(followers): record remote followers
fix(posts): hide non-public posts from other viewers
docs(adr): explain the HTTP contract
test(posts): cover soft deletion twice
```

Rules:

- One logical change per commit.
- The subject is imperative and under ~72 characters.
- The body explains **why**, not what the diff already says.
- No tool or AI attribution trailers. Commits are authored by the human who
  submits them.

## Code style

- `declare(strict_types=1)` and an SPDX header in every file.
- Tabs, as in the existing files.
- `final readonly` for value objects; a change returns a new instance.
- Business rules in `src/Service`, not in a handler.
- No dead code, no commented-out blocks, no unused imports.
- Comments explain *why* — an invariant, a security decision, a workaround.

## Pull requests

1. Branch from `main`.
2. Keep the diff focused; unrelated cleanups go into their own PR.
3. Describe the behaviour and how you verified it.
4. CI must be green on PHP 8.3, 8.4 and 8.5.
5. A route change needs both hosts checked against `bin/export-routes`.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your work is licensed under
AGPL-3.0-or-later, the license of this repository.
