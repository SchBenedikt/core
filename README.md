# Aloha Social Core

The host-neutral core of Aloha Social: domain, federation, services, HTTP
contract and platform ports.

> **This repository is a library.** It has no installable server, login page
> or process of its own. It is the shared foundation that the Aloha Social
> hosts run. The Nextcloud app, the standalone server and their bridges live in
> separate repositories.

The architecture and the boundary between the core and its hosts are described
in [ADR 0001](docs/adr/0001-shared-core-two-hosts.md) and
[the architecture document](docs/architecture.md).

## What is implemented

| Area | Implemented here |
| --- | --- |
| Posts and timelines | create, read and soft-delete local posts; replies with `in_reply_to_id`; direct posts with local recipients; public and home timelines; cursor pagination; post visibility, including followers-only reads by accepted local followers; post attachments |
| Social interactions | local favourites, boosts/reblogs and notifications with dismiss/unread state; polls with local voting; per-user blocks with outbound ActivityPub `Block`/`Undo(Block)`; local follow/unfollow and relationship reads |
| Follow graph | incoming and outgoing edges, individual and shared inboxes, batched delivery, optional follow policy, local-to-local follows and local-follow home timeline |
| Federation | HTTP signatures, actor documents, WebFinger, followers/following/outbox collections, shared inboxes, signed delivery, retries and dead letters; inbound `Follow`/`Undo`/`Create`/`Delete`/`Announce`; outbound `Block`/`Undo(Block)` and local `Announce`/`Undo(Announce)` |
| Remote content | remote posts and boosts, follow-based home timeline, per-user block filtering, guarded remote media, outbox backfill and bounded thread/context fetching |
| Media | image validation, originals and GD thumbnails, local upload/fetch/update/delete, attachments in posts and timelines, guarded remote-media ingestion |
| AT Protocol | account/record primitives, deterministic TIDs, XRPC `putRecord`/`deleteRecord`, idempotent publication rows, remote delete, OAuth session refresh and the authorization-code/PAR/revocation protocol client |
| Mastodon compatibility | optional, disabled-by-default subset under the configurable prefix: instance metadata/statistics, public timeline, status read/create/delete (including direct statuses and replies), reblogs, status context, poll creation/voting, favourites, local follow/unfollow and relationships, notifications (including mentions and reblogs) with dismiss/unread state, local search, media upload/update/delete, account profiles, follower/following lists, account statuses and verify credentials |
| HTTP | request/response, parameterised router, middleware, kernel, one JSON error shape, route manifest and OpenAPI export (56 routes; 28 under the compatibility prefix) |
| Persistence | in-memory and PDO repositories behind shared contract tests; durable `PdoJobQueue` and `PdoActivityLog`; schema classes for every core table |
| Host integration | `Application::forHost()` with injected ports, SQL repositories and the same kernel as the in-memory assembly |
| Migration | [ADR 0004](docs/adr/0004-incremental-migration-and-ownership.md), the [Nextcloud migration guide](docs/guides/migration-from-nextcloud.md) and the read-only `bin/validate-cutover` identity checker |
| Shared UI | `ui/` contains `@alohasocial/ui`: typed `HostBridge`, Vue plugin, typed `CoreApi`, network-free `FakeHostBridge`, `PostCard`, read-only `PublicTimeline` and read-only `PostDetail` |

The Mastodon-compatible surface is additive and disabled by default. It reuses
the same posts, media service, follow graph, notification repository and local
account directory as `/api/v1`; it is not a second status store. See
[the compatibility guide](docs/guides/mastodon-api.md).

The shared UI consumes existing core routes only. `PublicTimeline` reads
`GET /api/v1/timelines/public`; `PostDetail` reads
`GET /api/v1/posts/{id}`. Both cover loading, error and 401 states, and the
timeline covers empty and cursor paging as well. Host bridges and mount pages
are deliberately not in this repository.

## What remains outside this repository

These are real host or deployment responsibilities, not missing local routes:

- **Host application work:** login, sessions, account creation/reset, CSRF,
  bearer-token issuance, admin settings, navigation, templates, deployment and
  the worker process. The Nextcloud adapter and mount pages belong to
  `AlohaSocial/social`; see [Nextcloud host integration](docs/guides/nextcloud-host.md).
- **Host migrations:** the core owns the schema classes, not a migration
  runner. Creating the tables, adding documented columns/indexes and importing
  existing Nextcloud data stay with the host. See
  [migrating a Nextcloud installation](docs/guides/migration-from-nextcloud.md).
- **The full Mastodon server API:** OAuth authorization/token issuance and app
  registration; real remote timelines and remote delivery; favourite
  federation; remote notifications; streaming infrastructure; remote
  poll fetching/voting/federation; remote search; and remote
  relationship/admin APIs. The local subset and the exact external list are in
  [Mastodon-compatible API](docs/guides/mastodon-api.md#what-remains-external).
- **The full AT Protocol stack:** account-linking UI/browser callback, the
  short-lived flow store, credential storage, PDS selection, the full
  repository/AppView client, CAR/MST, blob uploads and the firehose. The
  protocol client and publishing foundation are implemented; see
  [AT Protocol publishing](docs/guides/atproto.md#remaining-external-work).
- **Host-specific UI bridges and mount pages**, including the Nextcloud
  `HostBridge`, rollout of the shared screens and the standalone bridge. See
  [shared UI](docs/guides/shared-ui.md).
- **An object-storage adapter** such as S3; it is a host/deployment choice. The
  core ships in-memory and local blob stores behind the `BlobStore` port.

Two boundaries remain deliberate product/contract decisions rather than host
work: direct posts and replies are local-only and address local account ids
(no remote direct delivery), and block propagation is outbound only — a peer
receives `Block`/`Undo(Block)`, but no public block collection is served and an
incoming block is not applied as a local moderation rule. See
[posts and timelines](docs/guides/posts-and-timelines.md) and
[blocks](docs/guides/blocks.md).

## Architecture at a glance

```
Host (auth, HTTP framework, database, worker)
  │
  ▼
Core\Kernel → Router → Middleware → Handler
                                  │
                                  ▼
                         Service (validation, transactions, fan-out)
                                  │
                ┌─────────────────┴─────────────────┐
                ▼                                   ▼
       Domain repositories                  JobQueue / InboxResolver
                │                                   │
                └──────────────┬────────────────────┘
                               ▼
                    Platform ports: Config, Clock, Connection,
                    BlobStore, HttpClient, ActivityLog, ...
```

`src/` contains no host import. `composer check:boundary` enforces that.
`Application::forHost()` is the production composition root;
`Application::inMemory()` is the test/demo assembly. Both use the same handlers
and services. See [architecture](docs/architecture.md) and
[using the core from a host](docs/guides/host-integration.md).

## Requirements

- PHP 8.3 or newer
- `ext-gd`, `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pdo` (runtime)
- `ext-curl` when the host uses `CurlHttpClient` for delivery, remote media or
  thread/context fetching
- `ext-pdo_sqlite` for the test suite
- Node.js 20 or newer for the `ui/` package

## Quick start

```bash
composer install
composer check          # lint, core boundary, attribution, links, PHP tests
php bin/core-demo       # real HTTP requests through the in-memory assembly
php bin/export-routes   # the HTTP contract as JSON (56 routes)
php bin/export-openapi  # the same contract as OpenAPI 3.1

cd ui
npm ci
npm run typecheck
npm test
npm run build
```

`bin/core-demo` writes a post, reads the public timeline, records a follower,
shows the expected 503 for a follow without delivery configuration, and
demonstrates the documented errors for unconfigured inboxes, actors and
WebFinger. It needs no database and no network.

### Quality gates

- `composer check` runs PHP lint, the core-boundary guard, the no-attribution
  commit check, relative-link validation and the full PHPUnit suite.
- The UI package has its own strict TypeScript check, Vitest suite and
  ES-module build. Its route-parity test invokes `php bin/export-routes`, so
  PHP dependencies must be installed first.
- CI runs the PHP matrix (8.3–8.5) and the UI job separately.

## The three shared contracts

1. **HTTP** — [`src/Contract/Routes.php`](src/Contract/Routes.php) is the only
   route declaration. Both hosts must serve exactly this manifest; the OpenAPI
   export is generated from it. See [ADR 0003](docs/adr/0003-http-contract.md).
2. **Platform** — everything the core needs from a host is an interface in
   `src/Platform/`. The core imports no host code; the boundary check fails the
   build otherwise.
3. **Data** — each table's schema lives next to its repository or adapter.
   `PostSchema`, `PollSchema`, `FavouriteSchema`, `BoostSchema`,
   `NotificationSchema`, `AttachmentSchema`, `FollowerSchema`,
   `FollowingSchema`, `RemotePostSchema`, `BlockSchema`,
   `AtprotoPublicationSchema`, `JobSchema` and `ActivityLogSchema` are shared
   by the in-memory contract tests and the PDO adapters, so a database dump can
   move between hosts.

## Layout

```
src/
  Platform/        ports the hosts implement — no host code ever enters here
  Domain/          posts, polls, media, federation, timelines, AT Protocol, repositories
  Service/         application behaviour and validation
  Protocol/        ActivityPub, RSA, HTTP signatures, ATProto records and XRPC
  Jobs/            durable queue contract, PDO/in-memory queues, worker
  Migration/       read-only validator for neutral cutover exports
  Http/            request, response, router, middleware, kernel
  Api/             one handler per route
  Contract/        Routes::manifest() — the single HTTP contract
  Security/        encryption at rest
  Support/         time and id helpers
tests/             unit, contract and end-to-end tests
ui/                @alohasocial/ui Vue package, HostBridge contract and tests
bin/               demo, route export and cutover validation
tools/             lint, link, attribution and host-neutrality guards
docs/              architecture, guides, reference, operations, ADRs
```

## Documentation

- [Documentation index](docs/README.md)
- [Architecture](docs/architecture.md)
- [Using the core from a host](docs/guides/host-integration.md),
  [Nextcloud host integration](docs/guides/nextcloud-host.md),
  [migrating a Nextcloud installation](docs/guides/migration-from-nextcloud.md),
  [shared UI and HostBridge](docs/guides/shared-ui.md)
- [Routes](docs/reference/routes.md), [error codes](docs/reference/error-codes.md),
  [configuration](docs/reference/configuration.md)
- [Mastodon-compatible API](docs/guides/mastodon-api.md),
  [AT Protocol publishing](docs/guides/atproto.md),
  [federation](docs/guides/federation.md),
  [inbound federation](docs/guides/inbound-federation.md),
  [media](docs/guides/media.md), [blocks](docs/guides/blocks.md)
- [Testing](docs/guides/testing.md), [development](docs/development.md),
  [roadmap](docs/roadmap.md)
- [Contributing](CONTRIBUTING.md), [security](SECURITY.md),
  [code of conduct](CODE_OF_CONDUCT.md), [changelog](CHANGELOG.md),
  [agent conventions](AGENTS.md)
- [ADR 0001 — one core, two hosts](docs/adr/0001-shared-core-two-hosts.md),
  [ADR 0002 — runtime](docs/adr/0002-runtime.md),
  [ADR 0003 — HTTP contract](docs/adr/0003-http-contract.md),
  [ADR 0004 — incremental migration and ownership](docs/adr/0004-incremental-migration-and-ownership.md),
  [ADR 0005 — shared UI](docs/adr/0005-shared-ui.md)

## License

AGPL-3.0-or-later. Every file carries an SPDX header; the full text is in
[`LICENSE`](LICENSE).
