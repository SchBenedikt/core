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
php bin/migration-inventory
cd ui && npm ci && npm run typecheck && npm test
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
  `PostRepositoryTestCase`, `AttachmentRepositoryTestCase`,
  `JobQueueTestCase`, `ActivityLogTestCase`); the optional instance-count
  capabilities are checked by the post and remote-post contracts.
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

The core implements the complete local social slice: posts and timelines, the
follow graph (individual and shared inboxes, batched delivery, local follows),
inbound and outbound ActivityPub, remote posts/boosts with a follow-based home
timeline, per-user blocks including outbound `Block`/`Undo(Block)`, local and
guarded remote media, outbox backfill, thread/context fetching, local
favourites, bookmarks, mutes, account lists, notifications, polls,
boosts/replies, typed remote Event/Article/Video objects with validated
metadata, remote Question polling with signed votes, quote posts with FEP-044f
authorization and revocation, per-reader keyword filters with hide/warn and contexts, a
disabled-by-default Mastodon compatibility subset (53 routes), the AT Protocol publishing foundation with OAuth refresh and the
authorization-code/PAR protocol client, author-only post editing with revision
history and ActivityPub `Update` ingestion/delivery, federated `Like` and
`EmojiReact` in both directions with identity-keyed reactions, separate counts
and idempotent `Undo`, public hashtag timelines,
private followed hashtags with their own timeline, the durable queue and replay log,
`Application::forHost()` and the read-only cutover validator.

`@alohasocial/ui` under `ui/` provides the `HostBridge` contract, a fake
bridge, the read-only public/hashtag timeline and post-detail screens, and the
shared write screens (actor header with follow, composer and notification
list).

Not in this repository: Nextcloud adapters/pages/import, the standalone
bootstrap, accounts, sessions and roles, the host-specific bridges and mount
pages, the external Mastodon and AT Protocol remainders, an object-storage
adapter and the admin UI. Identity is host-owned per `docs/adr/0006-host-owned-accounts.md`.

`docs/roadmap.md` is the canonical status and milestone document; do not
duplicate its status lists. See `docs/guides/host-integration.md` before
wiring a host.

## What not to do

- Do not add a dependency that a host would have to adopt (a framework, an
  ORM, a second HTTP stack).
- Do not add a `Platform` method for something only one host has; that is the
  host's business.
- Do not move Nextcloud controllers into this repository. Shared UI code
  belongs under `ui/` and only talks to a host through `HostBridge`; Nextcloud
  pages, host bridges and controllers stay in `AlohaSocial/social`.
- Do not add a host migration, installer, admin page or server-runtime detail
  to the core just because one product needs it.
