# Aloha Social Core

The host-neutral core of Aloha Social: domain, federation, services, HTTP
contract and platform ports.

This repository is a **library**. It contains no server, no login page and no
process you could install; it is what both Aloha Social hosts run.

| Host | What it is | Status |
| --- | --- | --- |
| [`AlohaSocial/social`](https://github.com/AlohaSocial/social) | the Nextcloud app | exists, not yet consuming this package |
| `AlohaSocial/server` | the standalone server, installed by script or container | planned |
| this repository | the code both of them share | foundation |

The architecture and the reasons behind it are in
[`docs/adr/0001`](docs/adr/0001-shared-core-two-hosts.md).

## Status

Implemented and tested here:

| Area | What |
| --- | --- |
| Platform ports | config, clock, blob storage (local and in-memory), image thumbnails (GD), database (PDO), outbound HTTP (curl) |
| Domain | posts, visibility, remote posts/boosts, per-user blocks, media metadata with local and remote post links, a bounded remote thread cache, post/remote-post/remote-thread/block/attachment repositories (in-memory and SQL, same contract tests), combined home-timeline repository |
| Follow graph | followers and following per local account, in-memory and SQL repositories behind one contract test, inbox resolution, signed `Follow`/`Undo` delivery |
| Inbound federation | signed `POST /inbox`, actor-key resolution over a port, `Follow` → edge + `Accept` behind an optional `FollowPolicy`, `Undo(Follow)`, signed `Create`/`Delete`/`Announce` from followed actors, replay guard, remote-post persistence, guarded remote-media attachment ingestion, bounded outbox catch-up with historical media repair, bounded on-demand thread/context fetching and per-user timeline blocking |
| Discovery | WebFinger handle resolution, ActivityStreams actor document with the published key, paged followers/following `OrderedCollection`s and the paged public outbox |
| Services | write/read/delete a post, public and follow-based home timelines, followers, following, instance description, image upload with validation and thumbnails, guarded remote-image import for incoming federated posts and historical outbox media catch-up, bounded remote thread/context reads |
| Federation | RSA key pairs, HTTP signatures (sign and verify), ActivityStreams `Create`/`Delete`, inbox fan-out, signed delivery jobs with backoff and dead letters, bounded actor-key caching |
| HTTP | request/response, parameterised router, middleware, kernel, one JSON error shape, route manifest with 24 routes and its JSON export |
| Host integration | `Application::forHost()` with injected ports, SQL repositories, durable `PdoJobQueue`/`PdoActivityLog` and a documented Nextcloud adapter path |
| Migration and ownership | [ADR 0004](docs/adr/0004-incremental-migration-and-ownership.md) fixes identity-preserving, feature-by-feature migration and the core/social/server boundary; `bin/validate-cutover` checks a neutral JSON export against the derived identities |
| Shared UI contract | [ADR 0005](docs/adr/0005-shared-ui.md) defines `@alohasocial/ui` and its `HostBridge`; the package extraction is still pending |
| Security | AES-256-GCM secret box for private keys at rest |

Deliberately not here yet: the AT Protocol stack, the Mastodon-compatible API,
an admin UI, a migration runner, the Vue UI package and the
Nextcloud/standalone host applications. Local image upload, thumbnails, guarded
remote-media ingestion for incoming federated posts and bounded thread/context
fetches are implemented; an object-storage adapter remains a host choice. The
[architecture document](docs/architecture.md#what-is-not-here-yet) lists the
remaining seams.

## Requirements

- PHP 8.3 or newer
- `ext-gd`, `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pdo` (runtime)
- `ext-curl` when the host uses `CurlHttpClient` for outbound delivery, remote media or thread/context fetching
- `ext-pdo_sqlite` (tests)

## Quick start

```bash
composer install
composer check          # lint, boundary, attribution, links, 613 tests
php bin/core-demo       # drives the core through real HTTP requests
php bin/export-routes   # the HTTP contract as JSON (24 routes)
php bin/export-openapi  # the same manifest as OpenAPI 3.1
php bin/validate-cutover var/migration-export.json  # read-only cutover parity
```

The demo builds the in-memory assembly, writes a post, reads the timeline,
records a follower, attempts a local follow (503 without delivery
configuration), shows the error shapes and shows that the inbox, the actor
routes and WebFinger answer 503 without a key or resolver — no database and no
network.

## Layout

```
src/
  Platform/        ports the hosts implement — no host code ever enters here
  Domain/          posts, media, remote posts, remote threads, followers, timelines, repositories, inbox resolution, activity log
  Service/         application behaviour and its validation
  Protocol/        ActivityPub documents, RSA keys, HTTP signatures
  Jobs/            durable queue contract, PDO and in-memory queues, worker
  Migration/       read-only validator for neutral cutover exports
  Http/            request, response, router, middleware, kernel
  Api/             one handler per route
  Contract/        Routes::manifest() — the single HTTP contract
  Security/        encryption at rest
  Support/         time and id helpers
tests/             unit, contract and end-to-end tests
bin/               demo, route export and cutover validation
tools/             lint, link, attribution and host-neutrality guards
docs/              index, architecture, guides, reference, operations, ADRs
```

## The three contracts

1. **HTTP** — [`src/Contract/Routes.php`](src/Contract/Routes.php) is the only
   place a route is declared. Both hosts must serve exactly this list; a host
   that drifts fails its parity test. See
   [ADR 0003](docs/adr/0003-http-contract.md).
2. **Platform** — everything the core needs from a host is one interface in
   `src/Platform/`. The Nextcloud and standalone adapters implement them; the
   core imports nothing from either. Enforced by `tools/check-core-boundary.php`
   on every run.
3. **Data** — the schema of a table lives next to its repository or adapter
   ([`PostSchema`](src/Domain/Post/PostSchema.php),
   [`AttachmentSchema`](src/Domain/Media/AttachmentSchema.php),
   [`FollowerSchema`](src/Domain/Federation/FollowerSchema.php),
   [`FollowingSchema`](src/Domain/Federation/FollowingSchema.php),
   [`RemotePostSchema`](src/Domain/Federation/RemotePostSchema.php),
   [`BlockSchema`](src/Domain/Federation/BlockSchema.php),
   [`JobSchema`](src/Jobs/JobSchema.php),
   [`ActivityLogSchema`](src/Domain/Federation/ActivityLogSchema.php)), so
   both databases have the same columns and a dump can move between them.

## Tests

```bash
composer test                                  # everything
php vendor/bin/phpunit tests/Http              # one directory
php vendor/bin/phpunit --filter HttpSignatureTest
```

The suite covers the platform adapters, the domain, both repository
implementations against one contract, the signature and ActivityPub
primitives, the queue's retry and dead-letter rules, routing and error
rendering, and end-to-end paths from HTTP through the queue to a signed
delivery to a real follower. The PDO queue and activity log are also tested
against a SQLite file with a fresh connection, proving that a pending job, a
retry and a replay decision survive a restart. The outgoing follow path is
tested end to end: Follow/Undo requests, signature verification, rollback and
retry. The inbound content path is tested through a signed `POST /inbox`, the
remote-post repository and the authenticated home timeline, including a
duplicate create, a targeted delete, a boost, an unfollowed actor and a
blocked actor. The per-user block path is tested through the real block API
and the combined timeline, including SQL filtering and unblocking. `MediaTest`
uploads a PNG through the real route into a temporary local blob store, checks
that the original and thumbnail are separate objects, attaches it to a post
and reads the alt text and URLs back through the post and both timelines. It
also proves wrong, corrupt and oversized uploads leave no blob and no post.
`RemoteMediaTest` adds the guarded inbound and outbox-backfill media paths,
including historical repair, idempotent repeats and failing candidates.
`ApplicationHostTest` repeats the Create-to-home path against SQL repositories.

## Documentation

- [Documentation index](docs/README.md)
- [Architecture](docs/architecture.md)
- [Using the core from a host](docs/guides/host-integration.md),
  [Nextcloud host integration](docs/guides/nextcloud-host.md),
  [migrating a Nextcloud installation](docs/guides/migration-from-nextcloud.md),
  [shared UI and HostBridge](docs/guides/shared-ui.md)
- [Development](docs/development.md)
- [Routes](docs/reference/routes.md), [error codes](docs/reference/error-codes.md),
  [configuration](docs/reference/configuration.md)
- [Inbound federation](docs/guides/inbound-federation.md),
  [media](docs/guides/media.md), [blocks](docs/guides/blocks.md),
  [inbox activities](docs/reference/inbox-activities.md),
  [keys and clocks](docs/operations/keys-and-clocks.md)
- [Actor document](docs/reference/actor-document.md),
  [peer discovery](docs/guides/peer-discovery.md),
  [followers collection](docs/guides/followers-collection.md),
  [following collection](docs/guides/following-collection.md),
  [outbox](docs/guides/outbox.md),
  [WebFinger](docs/guides/webfinger.md)
- [ADR 0001 — one core, two hosts](docs/adr/0001-shared-core-two-hosts.md)
- [ADR 0002 — runtime](docs/adr/0002-runtime.md)
- [ADR 0003 — HTTP contract](docs/adr/0003-http-contract.md),
  [ADR 0004 — incremental migration and ownership](docs/adr/0004-incremental-migration-and-ownership.md),
  [ADR 0005 — shared UI](docs/adr/0005-shared-ui.md)
- [Contributing](CONTRIBUTING.md), [security](SECURITY.md),
  [code of conduct](CODE_OF_CONDUCT.md), [changelog](CHANGELOG.md)
- [Agent conventions](AGENTS.md)

## License

AGPL-3.0-or-later. Every file carries an SPDX header; the full text is in
[`LICENSE`](LICENSE).
