# Roadmap

Status of the core, not a promise about dates. Last reviewed: 2026-11-13.

This is the canonical status document. The guides explain how a part works;
this page says what exists, what is next and which repository owns it. If a
guide and this page disagree, the route manifest, the schemas and the tests
win — and the disagreement is a documentation bug.

## Goal

`alohasocial/core` is the host-neutral core of Aloha Social: domain, services,
federation protocol, HTTP contract, platform ports and the shared Vue package
`@alohasocial/ui`. The product is three artifacts and one behaviour:

```
                    alohasocial/core (this repository)
                    domain · services · protocols · contract
                       │                          │
        AlohaSocial/social                AlohaSocial/server
        Nextcloud host                    standalone host
        (OCP adapters, NC pages)          (kernel, auth, installer)
```

A slice is not done because a route exists. It is done when the import, the
identity checks, the contract tests and a real peer smoke test have passed in
the host that serves it. See
[ADR 0004](adr/0004-incremental-migration-and-ownership.md).

### Definition of done for the product

1. Both hosts serve exactly [`Routes::manifest()`](../src/Contract/Routes.php)
   and the same JSON error shape; a parity test compares their registered
   routes with `bin/export-routes`.
2. The Nextcloud app runs the migrated slices on the core without changing
   actor URLs, key ids, note URLs, `Create`/`Delete`/`Follow`/`Undo` activity
   ids or outgoing follow row ids, and can roll back one account by flag.
3. `AlohaSocial/server` installs from scratch and publishes a post through the
   same objects the core tests, with its own accounts, sessions and worker.
4. The shared screens run in both hosts through one `HostBridge` each, and the
   host smoke tests (login, one read, one write, logout) pass.
5. Mastodon compatibility and AT Protocol remain additive, bounded and
   disabled by default where documented; no host-specific branch appears in
   `src/`.

## Where we are (2026-11-13)

| Measure | Value |
| --- | --- |
| HTTP contract | 80 routes, 43 of them under the Mastodon prefix |
| Persistence | 17 schema classes, in-memory and PDO adapters behind shared contract tests |
| Tests | 112 PHP test files (1036 tests, 4708 assertions) and 8 UI test files (63 tests) |
| Release | `0.3.0` (2026-11-13) is cut; `0.2.0` is tagged; `0.1.0` exists only as a CHANGELOG entry; none is on Packagist yet |
| Local feature slice | complete for the scope described below |
| Hosts | Nextcloud adapter not started; standalone repository does not exist yet |

### Implemented in the core

- **Posts and timelines** — create, read, soft-delete, visibility, replies,
  local direct posts with `recipient_ids`, public, follow-based home and
  hashtag timelines with stable cursors, blocks, mutes, exclusive lists and
  boosts. See [posts and timelines](guides/posts-and-timelines.md).
- **Social interactions** — local favourites, private bookmarks, local mutes,
  private account lists and list timelines, notifications (favourite, follow,
  mention, reblog) with dismiss/unread state, polls with local voting, local
  follow/unfollow and relationship reads. These routes live under the optional
  Mastodon compatibility prefix, not under `/api/v1`. See
  [Mastodon-compatible API](guides/mastodon-api.md).
- **Follow graph and federation** — incoming and outgoing edges, individual
  and shared inboxes, batched delivery, optional follow policy, local-to-local
  follows, signed `Follow`/`Undo`, inbound `Follow`/`Undo`/`Create`/`Delete`/
  `Announce`, outbound `Block`/`Undo(Block)` and local `Announce`/
  `Undo(Announce)`, retries with backoff, dead letters and a durable replay
  log. See [federation](guides/federation.md),
  [inbound federation](guides/inbound-federation.md) and
  [jobs and delivery](guides/jobs-and-delivery.md).
- **Discovery** — actor document, WebFinger, followers/following/outbox
  collections with bounded pages. See [peer discovery](guides/peer-discovery.md).
- **Remote content** — stored remote posts and boosts, guarded remote media,
  bounded outbox backfill and on-demand thread/context fetching with an
  idempotent object cache. See [remote threads](guides/remote-threads.md) and
  [remote backfill](guides/remote-backfill.md).
- **Media** — JPEG/PNG/WebP validation, untouched originals, GD thumbnails,
  authenticated upload, public fetch, post attachments and SSRF-safe remote
  ingestion. See [media](guides/media.md).
- **AT Protocol** — public text-only publishing through authenticated XRPC
  `putRecord`/`deleteRecord`, deterministic TIDs, idempotent publication rows,
  a read of the stored publication state, remote delete, OAuth session refresh
  and the authorization-code/PAR/revocation protocol client. Account linking
  UI, credential storage and PDS selection stay with the host. See
  [AT Protocol publishing](guides/atproto.md) and
  [two protocols, one model](guides/dual-protocol.md).
- **Mastodon compatibility** — 43 local routes behind a configurable,
  disabled-by-default prefix: instance metadata/statistics, public and list
  timelines, status read/create/delete, reblogs, context, polls, favourites,
  bookmarks, mutes, lists, notifications, local search, media management,
  account profiles, follower/following lists and verify credentials.
- **HTTP contract** — one route manifest, generic kernel, one error shape,
  OpenAPI 3.1 export and stable error codes. See [routes](reference/routes.md).
- **Persistence and migration** — schema classes for every core table,
  in-memory and PDO repositories behind shared contract tests, durable
  `PdoJobQueue`/`PdoActivityLog`, `Application::forHost()`, the neutral cutover
  export and the read-only validator. See [database](guides/database.md) and
  [migrating a Nextcloud installation](guides/migration-from-nextcloud.md).
- **Local interaction API** — `GET /api/v1/accounts/{id}` returns a public
  local profile with the viewer relationship; `POST`/`DELETE
  /api/v1/accounts/{id}/follow` write the local edge idempotently;
  `GET /api/v1/notifications` pages unread events with the unread count and
  `POST .../notifications/{id}/dismiss`, `.../notifications/clear` and
  `GET .../notifications/unread_count` manage the read marker. These are
  neutral routes, not the Mastodon-shaped projection, and reuse the existing
  follow graph and notification table.
- **Shared UI** — `@alohasocial/ui` with the typed `HostBridge`, Vue plugin,
  typed `CoreApi`, network-free `FakeHostBridge`, `PostCard`, read-only
  `PublicTimeline` (public or hashtag timeline), `PostDetail`, and the write
  screens `ActorHeader` (follow control), `PostComposer` and
  `NotificationList` (dismiss, clear, paging).
  See [shared UI](guides/shared-ui.md).

### Not in this repository

- The Nextcloud adapter, mount pages, admin settings and the data import.
- The standalone bootstrap, accounts/sessions (host-owned per
  [ADR 0006](adr/0006-host-owned-accounts.md)), installer, container and
  migration runner.
- The host bridges and mount pages that render the shared screens inside the
  Nextcloud page and the standalone server.
- The external remainder of the Mastodon server API and of the AT Protocol
  stack.
- An object-storage adapter such as S3; the core ships memory and local stores
  behind `BlobStore`.

## Milestones

State legend: **done** — shipped and tested; **partial** — a documented slice
exists; **open** — not started; **external** — depends on a host, a remote
server or a product decision.

| # | Milestone | State | Owner | Exit criterion |
| --- | --- | --- | --- | --- |
| M0 | Core foundation | done (`0.1.0`) | core | Ports, posts, HTTP signatures, delivery queue, kernel and boundary guard are green |
| M1 | Local social slice | done | core | The local post/follow/media/interaction surface is complete, disabled-by-default compatibility surfaces included |
| M2 | Release and documentation consistency | done (`0.2.0` tagged, `0.3.0` cut; registry publication pending) | core | `composer check` green, documentation matches the manifest, `0.2.0` tagged |
| M3 | Nextcloud host cutover | open | `social` | Adapters, bridge, import, shadow run, validator `PASS`, one account cut over and rolled back by flag |
| M4 | Standalone host | open | `server` | Fresh install with its own accounts/sessions (host-owned, ADR 0006), worker and installer publishes a post and federates |
| M5a | Host-neutral interaction API and shared write screens | done | core / `ui` | Profile/follow, notification and composer routes plus the three screens are tested end to end |
| M5 | Shared UI rollout | partial | `ui` + hosts | Both hosts mount the shared screens through their bridge; smoke tests pass |
| M6 | Compatibility remainder | partial / external | core + hosts | The external remainder is either implemented by a host or explicitly not planned |
| M7 | Operations and packaging | open | `server` / ops | Installer, container, backup, cleanup jobs and an object-storage option exist |

## Now (core)

1. **Keep the contract documentation honest.** `DocumentationTest` compares
   `docs/reference/routes.md` with `Routes::manifest()`, checks the advertised
   counts in the README and this page, and verifies that every `*Schema` class
   appears in the database guide. Add the configuration reference to the same
   guard when it drifts again.
2. **Publish the registries.** `0.3.0` is cut and tagged; Packagist and the npm
   registry still need maintainer credentials. A host should be able to install
   a version instead of `dev-main`.
3. **Close the identity decision.** [ADR 0006](adr/0006-host-owned-accounts.md)
   (proposed) recommends keeping accounts, sessions and credentials host-owned:
   no `Platform\Identity` port and no core user table. Review and accept it;
   the standalone then implements auth itself and passes `userId` as every
   host does.
4. **Mount the finished shared screens in the hosts.** `ActorHeader`,
   `PostComposer` and `NotificationList` are complete and covered by
   `FakeHostBridge` tests. The remaining M5 work is the Nextcloud bridge and
   mount page, then the standalone bridge; neither belongs in this repository.
   Do not move a Nextcloud-only page into the package.

## Next

| Item | Seam | Owner | Done when |
| --- | --- | --- | --- |
| Nextcloud OCP adapters, controller and route registration | `social` | `social` | Registered routes equal `bin/export-routes`; the host parity test is green |
| Nextcloud `HostBridge`, mount pages and navigation | `social` | `social` | `PublicTimeline` and `PostDetail` render against the real bridge; login, 401 and logout work |
| Nextcloud posts/follows import and actor-URL parity | `social` | `social` | The neutral export passes `bin/validate-cutover`; counts and checksums match the source |
| Cutover: posts/timelines → actor surface → follows/delivery → inbox → UI | `social` | `social` | One account runs end to end on the core and a flag restores the old path |
| Standalone repository and its host-owned account/session layer | `server` + ADR 0006 | `server` | A second host boots with its own accounts and the same kernel |
| Standalone migrations, installer, worker and container | `server` | `server` | A fresh install creates the schema, seals a key, publishes a post and drains the queue |
| Mount the shared actor/follow, composer and notification screens | host bridges | `ui` + hosts | Both hosts complete one read and one write through the real bridge |
| Mastodon OAuth/token issuance and app registration | host identity service | `social` / `server` | A third-party client can authorize and call the compatibility routes |
| Registry publication: Packagist and npm | core | core | A host installs `0.3.0` instead of `dev-main` |

## Later

- **Locally implementable Mastodon remainder:** filters, followed tags,
  scheduled posts, follow requests/locked accounts and the wider
  account-management surface. These are product decisions, not
  protocol blockers; they are not part of the local slice above.
- **AT Protocol after account linking:** facets, mentions, link cards, media
  embeds, reply/thread mapping and the full repository/AppView client.
- **Object storage:** an S3-compatible `BlobStore` adapter when a host needs it.
- **Operations:** retention jobs for the thread cache, read notifications and
  orphaned blobs; backup/restore and monitoring for the standalone host.
- **Admin UI:** host pages; the core keeps the settings rules.

## Explicitly not planned

- A rewrite in another language.
- A "fake Nextcloud" shim as a permanent architecture (see
  [ADR 0001](adr/0001-shared-core-two-hosts.md)).
- Storing configuration or keys in this repository.
- Remote direct delivery and remote reply threading: direct posts and replies
  address the local model only. Remote recipients/inboxes and fetching a
  remote parent are not part of this slice.
- A public block collection and applying an incoming `Block` as a per-user
  moderation rule: block propagation is outbound, and the optional instance
  policy remains the host seam for instance-wide refusal.

## Compatibility remainder

These parts are implemented by a host or need remote data; they are not hidden
local gaps. The detailed lists live in the guides.

- **Mastodon:** OAuth authorization/token issuance/revocation and app
  registration; remote timelines and delivery; favourite federation (outbound
  `Like`/`Undo` and remote counts); remote boost federation; remote
  notifications; streaming; remote poll fetching/voting/`Question` federation;
  remote search; remote relationship/admin APIs. See
  [Mastodon-compatible API](guides/mastodon-api.md#what-remains-external).
- **AT Protocol:** account-linking UI/browser callback, the short-lived flow
  store, credential storage and PDS selection; a full repository/AppView client
  (CAR/MST); blob uploads; the firehose; facets and media embeds; migrating the
  existing Nextcloud implementation. See
  [AT Protocol publishing](guides/atproto.md#remaining-external-work).
- **Hosts:** login, sessions, account creation/reset, CSRF, bearer-token
  issuance, admin settings, navigation, templates, deployment and the worker
  process. The core never issues tokens or owns a session.

## Release history

| Version | Date | Content |
| --- | --- | --- |
| `0.1.0` | 2026-11-11 | Foundation: ports, posts, timelines, ActivityPub, delivery queue, HTTP kernel, route manifest, boundary guard |
| `0.2.0` | 2026-11-13 | The local social slice: media, remote content, blocks, direct posts, interactions, Mastodon compatibility, AT Protocol foundation and publication-state read, dual-protocol guide, shared UI reads, migration validator |
| `0.3.0` | 2026-11-13 | Neutral profile/follow/notification API, shared `ActorHeader`/`PostComposer`/`NotificationList` write screens, host-level SQL coverage and release documentation |

`0.1.0` has a CHANGELOG entry but no git tag. `0.2.0` and `0.3.0` are tagged
and recorded in the [changelog](../CHANGELOG.md); registry publication is the
remaining M2 step.
