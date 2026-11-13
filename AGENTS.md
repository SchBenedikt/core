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

Implemented: posts and timelines, the follow graph (including stored peer
shared inboxes and batched delivery), inbound and outbound ActivityPub (signed
`POST /inbox` and `/inbox/shared`, signed `Follow`/`Undo` delivery, `Accept`,
signed `Create`/`Delete`/`Announce` storage), remote posts and boosts with a
follow-based home timeline, per-user blocks and their timeline filter, the
public text-only AT Protocol publishing foundation (account/publisher ports,
deterministic TIDs, XRPC `putRecord`/`deleteRecord`, idempotent publication
rows, remote delete for published posts, OAuth session refresh and the
authorization-code/PAR/revocation protocol client with ES256 DPoP proofs and a
disabled-by-default publish route), the optional disabled-by-default Mastodon
compatibility subset (instance metadata/statistics, public timeline, status
read/create/delete with local poll creation/voting, local favourite/unfavourite,
private local bookmarks, local mutes with optional notification filtering,
local boost/reblog, reply context and counts, local
follow/unfollow and relationship reads, local notification listing with
dismiss/unread state,
local status/actor search, media upload/update/delete,
local account profiles, paged local follower/following lists, account statuses,
verify credentials; `write:statuses`,
`write:favourites`, `write:bookmarks`, `write:mutes`, `write:follows`,
`write:media`, `write:notifications`, `read:accounts`, `read:follows`,
`read:bookmarks`, `read:mutes`, `read:notifications` and `read:search`
scopes, id-aware and offset pagination and `Idempotency-Key`), the
actor document, followers and following collections, outbox and WebFinger, a
bounded actor-key cache, an optional `FollowPolicy`, a bounded remote-outbox
backfill with historical media catch-up, bounded thread/context fetching with
an idempotent object-id cache, local image upload with validation,
GD thumbnails, post attachments and public media fetch, guarded remote-media
ingestion for incoming `Create`/`Announce` attachments, the HTTP contract, the
platform ports, durable
`PdoJobQueue`/`PdoActivityLog` and `Application::forHost()` for a real host,
plus the read-only cutover validator for the neutral migration export.
The migration and repository-owner contracts are accepted in ADR 0004; the
actual Nextcloud data import remains host work. The shared-UI contract from ADR
0005 is implemented as `@alohasocial/ui` under `ui/`; host-specific bridges,
mount pages and navigation remain in their host repositories.
Not implemented in the core: the AT Protocol account-linking UI/browser
callback, credential store and PDS selection (the authorization-code/PAR flow
and token revocation are implemented), the full
repository/AppView/media/firehose stack, the external Mastodon server
remainder (OAuth/token issuance and app registration, remote timelines and
delivery, favourite federation, remote boost federation, remote notifications,
streaming, remote poll fetching/voting/federation, remote search, account/admin
and remote relationship APIs), and an
object-storage adapter. Local Mastodon notification listing with
dismiss/unread state, local status/actor search, local poll creation/voting,
local boosts/reblogs, private bookmarks, local mutes, reply context/counts,
instance metadata/statistics, local account profiles and paged local
follower/following lists are done. Lists remain a future local slice; the rest is
host migration or external federation work. The local AT Protocol publishing slice is
documented in `docs/guides/atproto.md`; the local Mastodon compatibility
subset is in `docs/guides/mastodon-api.md`; the rest is in
`docs/roadmap.md`. Local
and remote media, including the SSRF/size limits, failure reasons, switch and
cleanup contract, are documented in `docs/guides/media.md`; thread/context
fetching is documented in `docs/guides/remote-threads.md`. See `docs/roadmap.md` before starting a large feature,
and `docs/guides/host-integration.md` before wiring a host.

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
