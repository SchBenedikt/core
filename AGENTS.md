# AGENTS.md

`alohasocial/core` is the host-neutral core of Aloha Social. It is a library:
domain, services, federation protocol, HTTP contract and platform ports. The
Nextcloud app and the standalone server are thin hosts around it.

Read `docs/architecture.md` and `docs/adr/0001-shared-core-two-hosts.md` before
changing a subsystem.

## The one rule

**Nothing in `src/` may reference a host.** No `OCP\`, no `OCA\`, no
`nextcloud/*` dependency, no `$_SERVER`/session/global state. `composer
check:boundary` enforces it and must stay green.

If a feature needs something from the host, add a port in `src/Platform/` and
implement it in the host. A comment may *name* a host to explain an adapter;
code may not call one.

## Where a feature belongs

| Change | Repository |
| --- | --- |
| Social behaviour both hosts need: posts, follows, ActivityPub, AT Protocol, media rules, routes | `core` (`src/` or `ui/`) |
| Nextcloud-only adapter, controller, template, admin setting or auth integration | `AlohaSocial/social` |
| Standalone bootstrap, accounts/auth, installer, container, systemd or server migrations | `AlohaSocial/server` |
| Shared Vue component, composable or `HostBridge` | `@alohasocial/ui` (under `ui/` when extracted) |

A host-specific exception never becomes a branch in `src/`. If both hosts need
the behaviour, put the rule and its tests in the core and keep only the adapter
in each host. Migrations are host-owned: the core shares schema classes and
contracts, not a Nextcloud importer or a standalone migration runner. Read
[ADR 0004](docs/adr/0004-incremental-migration-and-ownership.md) before a
data or cutover change and [ADR 0005](docs/adr/0005-shared-ui.md) before a UI
change.

## Commands

```sh
composer check                     # lint + boundary + attribution + links + tests (the gate)
composer check:attribution
composer check:links
composer test
php vendor/bin/phpunit tests/Http
php vendor/bin/phpunit --filter HttpSignatureTest
php bin/core-demo
php bin/export-routes
php bin/export-openapi
php bin/validate-cutover <migration-export.json>
```

PHP 8.3+ is required; see `docs/development.md` for a rootless setup. The
full documentation index is `docs/README.md`.

## Conventions

- `declare(strict_types=1)` and an SPDX header in every file
  (`SPDX-FileCopyrightText: 2026 Aloha Social contributors`,
  `SPDX-License-Identifier: AGPL-3.0-or-later`). Files that cannot carry a
  header go into `REUSE.toml`.
- Value objects are `final readonly`; a change returns a new instance.
- Business rules live in `src/Service`; handlers only map input and output.
- A route exists only in `src/Contract/Routes.php`.
- Every new behaviour needs a test that fails without it. Every new port
  implementation must pass the existing contract test (`BlobStoreTestCase`,
  `PostRepositoryTestCase`, `JobQueueTestCase`, `ActivityLogTestCase`).
- Conventional Commits with a scope: `feat(posts): …`, `fix(signature): …`.
- Commits carry no tool or AI attribution trailers; the author is the human
  who submits the work.
- No dead code, no unused imports, no commented-out blocks.
- Comments explain *why* — invariants, security decisions, workarounds.

## Security

`Protocol/ActivityPub/HttpSignature` and `Security/OpenSslSecretBox` are the
security boundary. Never weaken a check to make a test or a peer pass. A
refusal always has a stable `reason`; keep it stable, tests and logs depend on
it.

Do not log request bodies, private keys or sealed payloads.

## Current scope

Implemented: posts and timelines, the follow graph, inbound and outbound
ActivityPub (signed `POST /inbox`, signed `Follow`/`Undo` delivery, `Accept`,
signed `Create`/`Delete`/`Announce` storage), remote posts and boosts with a
follow-based home timeline, per-user blocks and their timeline filter, the
actor document, followers and following collections, outbox and WebFinger, a
bounded actor-key cache, an optional `FollowPolicy`, a bounded remote-outbox
backfill, the HTTP contract, the platform ports, durable
`PdoJobQueue`/`PdoActivityLog` and `Application::forHost()` for a real host,
plus the read-only cutover validator for the neutral migration export.
The migration, repository-owner and shared-UI contracts are accepted in ADR
0004/0005; the actual Nextcloud data import and `@alohasocial/ui` extraction
happen in their host workflows.
Not implemented in the core: remote-post backfill/thread fetching, media,
AT Protocol. See `docs/roadmap.md` before starting a large feature, and
`docs/guides/host-integration.md` before wiring a host.

## What not to do

- Do not add a dependency that a host would have to adopt (a framework, an
  ORM, a second HTTP stack).
- Do not add a `Platform` method for something only one host has; that is the
  host's business.
- Do not move the Vue UI or the Nextcloud controllers into this repository
  before the `ui/` extraction starts. Shared UI code belongs under `ui/` and
  only talks to a host through `HostBridge`; Nextcloud pages and controllers
  stay in `AlohaSocial/social`.
- Do not add a host migration, installer, admin page or server-runtime detail
  to the core just because one product needs it.
