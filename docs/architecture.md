# Architecture

The core is a hexagonal application: the domain and its services know nothing
about the host, and everything a host must provide is an interface in
`src/Platform/`.

```
            ┌─────────────────────────────────────────────┐
  HTTP ───► │ Kernel → Router → Middleware → Handler       │
            │                      │                      │
            │                      ▼                      │
            │                Service (validation,          │
            │                transactions, fan-out)        │
            │                 │            │               │
            │                 ▼            ▼               │
            │       Domain/Repositories  JobQueue        │
            │       InboxResolver                        │
            └─────────────────┬────────────┬───────────────┘
                              │            │
                   ┌──────────▼──┐   ┌─────▼─────────┐
                   │ Connection  │   │ HttpClient    │
                   │ BlobStore   │   │ Clock, Config │
                   │ (host ports)│   │ (host ports)  │
                   └─────────────┘   └───────────────┘
```

## Layers

| Layer | Responsibility | Must not |
| --- | --- | --- |
| `Http` | routing, request/response, middleware, error rendering | contain business rules |
| `Api` | one handler per route: input → service → output | hold state between requests |
| `Service` | validation, orchestration, transactions, queue pushes | know about HTTP or SQL |
| `Domain` | entities, value objects, repository and resolver ports | depend on anything else |
| `Protocol` | ActivityStreams documents, RSA, HTTP signatures | read the database |
| `Jobs` | queue contract, worker loop, retry rules | know what a job means |
| `Platform` | ports a host implements | import a host |

`Domain\Federation` holds the follow graph, the remote-post store, the
per-user block store, the remote-attachment candidate and the `InboxResolver`
seam. `Domain\Timeline` owns the combined home-timeline read model.
`Domain\Media` owns attachment metadata, its local post link and its remote
post link; the bytes go through the `BlobStore` port and image processing
through the `ImageProcessor` port. These are domain ports, not platform ports:
the question "where does this audience live?" belongs to the application, only
the inbox URLs are data.

## Request lifecycle

1. The host authenticates and builds a `Core\Http\Request` with `userId`.
2. `Kernel` asks the `Router` for a `RouteMatch` (404/405 are decided here).
3. The matched route and its path parameters become request attributes.
4. Middleware runs outermost first — `RequireAuthentication` refuses a route
   with `requires_auth` when there is no user.
5. The `HandlerResolver` builds the handler lazily and it produces a `Response`.
6. Any `HttpException` becomes the documented JSON error; anything else is
   logged and answered with a 500 that does not leak internals unless the
   kernel is in debug mode.

## Host assembly

`Application::forHost()` is the production composition root. It takes the
host's `Config`, `Connection`, `HttpClient` (optionally `BoundedHttpClient` and
a `HostResolver`), `RsaKeyPair`, `LocalActorDirectory`, durable `JobQueue` and
`ActivityLog`, builds the PDO repositories and services, and returns the same
`Kernel` the reference assembly uses. Remote media is wired only when both the
bounded HTTP capability and a blob store are present. The core supplies
`PdoJobQueue` and `PdoActivityLog` for the last two over that same connection.
`Application::inMemory()` remains the test/demo path; both go through one
private assembly, so the two cannot drift apart. The host still owns
authentication, migrations, the HTTP controller and the worker process. See
[using the core from a host](guides/host-integration.md).

## Repository boundaries and migration

Three repositories share the product, each with one kind of responsibility:
`core` owns social behaviour and protocol rules that both products need;
`AlohaSocial/social` owns Nextcloud adapters, pages, admin settings and
Nextcloud auth; `AlohaSocial/server` owns the standalone bootstrap, accounts,
installer and deployment. A feature needed by only one host stays there. The
full decision and data-ownership rules are in
[ADR 0004](adr/0004-incremental-migration-and-ownership.md).

Existing installations move with a strangler migration. The host imports its
old data into the core schemas, exports the neutral validation dataset and
runs `bin/validate-cutover` to verify that actor URLs, key ids, post URLs,
Create/Delete/Follow/Undo activity ids and outgoing follow row ids did not
change before it cuts over one slice at a time. The core has no Nextcloud
importer and no universal migration runner; those would make one host's schema
part of the shared architecture. The concrete procedure and export format are
in [migrating a Nextcloud installation](guides/migration-from-nextcloud.md).

The shared Vue front end is `@alohasocial/ui`, with a `HostBridge` per host.
It is a separate npm artifact under `ui/` when extraction starts, not a fourth
backend and not a place for OCP or server code. See
[ADR 0005](adr/0005-shared-ui.md) and [shared UI](guides/shared-ui.md).

## Writing a post (the implemented vertical slice)

```
POST /api/v1/posts
  └─ CreatePostHandler        parse and validate the shape, including attachment_ids
      └─ PostService::create  trim, length, content warning, visibility,
          │                   attachment ownership and references
          ├─ AttachmentRepository::find   one lookup per referenced id
          ├─ PostRepository::insert       one row + their links in one transaction
          ├─ InboxResolver::inboxesFor    the author's stored followers
          └─ JobQueue::push (per inbox)   {"inbox", "activity", "key_id"}
                                              │
JobWorker::runOnce ──────────────────────────┘
  └─ FederationDeliveryHandler
      ├─ json_encode the activity built when the post was written
      ├─ HttpSignature::sign   (request-target) host date digest
      └─ HttpClient::request   non-2xx → retry with backoff, then dead letter
```

The inbox list comes from the follow graph: `FollowerInboxResolver` asks
`FollowerRepository::inboxesFor()` for the author's stored followers. Each row
carries the inbox URL, so delivery needs no network lookup at post time, and
inbox URLs are de-duplicated before a job is queued.

The activity is built at write time and stored in the job, not rebuilt at
delivery time: an edit between queueing and sending must not change what a
retry sends. Its ids are derived from the instance URL and the post id, so a
retry is idempotent for the receiving server.

## Receiving an activity

```
POST /inbox
  └─ InboxHandler          JSON, then delegate
      └─ InboxService      signature → actor → policy → replay → apply
          ├─ ActorResolver::resolveByKeyId   (HTTP or fixture)
          ├─ HttpSignature::verify           raw body, signed headers
          ├─ BlockedActorPolicy::isBlocked   optional host policy
          ├─ FollowPolicy::accepts            optional Follow approval
          ├─ ActivityLog::claim              replay guard
          ├─ FollowingService::localUserIds  content authorization
          ├─ RemotePostRepository::insert    Create/Announce
          ├─ RemoteMediaService::import       best-effort images after insert
          │   ├─ RemoteMediaFetcher          HTTP, redirects, SSRF and size guard
          │   ├─ MediaService::upload         existing validation + storage
          │   └─ RemotePostRepository::linkAttachments
          ├─ RemotePostRepository::softDelete Delete
          ├─ FollowerService::follow         Follow → edge
          └─ JobQueue::push                  Accept → follower inbox
```

Every refusal is an `InboxRejectedException` with a stable reason; the handler
is the only place that maps a reason to an HTTP status. The inbox is enabled
only when an actor resolver, an HTTP client and a signing key are all present —
otherwise it answers 503 instead of pretending.

## Backfilling an outbox

```
RemoteOutboxBackfill::backfill
  ├─ following/filter and outbox paging guards
  ├─ RemotePostFactory          same Create/Announce rules as the inbox
  ├─ RemotePostRepository::insert
  └─ RemoteMediaService::import
      └─ the bounded fetcher/validator/storage path above
```

A new activity is handed to the media importer after its insert. An activity
whose primary key already exists is still passed to the idempotent importer:
this is how an explicit backfill repairs a post stored while
`social/media.remote_fetch` was off or while an attachment failed, without a
second post row. The importer keeps existing links when one advertised URL can
no longer be fetched, and media failures never change the post's
stored/duplicate result. The backfill remains an explicit host operation; it
is not a route and does not schedule itself.

## Home timeline

```
GET /api/v1/timelines/home
  └─ HomeTimelineHandler
      └─ TimelineService::home
          └─ HomeTimelineRepository::forUser
              ├─ PostRepository::ownTimeline        reader's own posts
              └─ RemotePostRepository::timelineForActors
                    ├─ FollowingRepository::actorIds reader's outgoing graph
                    └─ BlockRepository::actorIds     actors the reader blocks
```

`PdoHomeTimelineRepository` performs the same combination in one SQL union:
the local branch is scoped to the reader, the remote branch joins
`social_following` on `actor_id` and excludes the reader's `social_block`
rows, and only then are the rows sorted and limited. Filtering each half separately and merging in PHP would make the
cursor ambiguous at a page boundary. A boost is a remote row with
`kind = 'boost'`; it is returned with `boosted_by` set, never duplicated as a
standalone post.

Remote rows are shared by all local followers; the join, not a per-user copy,
is what scopes visibility. A per-user block is a second, private filter on the
same rows: it hides the actor's own content and boosts of that actor's content
from that reader's timeline without deleting the row or affecting another
follower. Deleting a remote post sets `deleted_at`,
so the next home query excludes it while the activity id stays claimed.

An imported image is a normal local `Attachment` with a nullable `source_url`.
`MediaService::presentTimelineEntries` resolves local entries through
`social_post_attachment` and remote entries through
`social_remote_post_attachment`, then presents the same local `url`,
`thumbnail_url` and `alt_text` shape for both. The remote URL is the unique
idempotence key and is never required by a reader.

## Following an actor

```
POST /api/v1/following
  └─ FollowRemoteActorHandler  parse actor + inbox
      └─ FollowingService::follow
          ├─ FollowingRepository::insert
          ├─ ActivityFactory::followActor       Follow with derived id
          └─ FederationDeliveryHandler::deliver
              ├─ HttpSignature::sign   (request-target) host date digest
              └─ HttpClient::request   non-2xx → throw, edge rolled back
DELETE /api/v1/following/{id}
  └─ UnfollowRemoteActorHandler
      └─ FollowingService::unfollowById
          ├─ ActivityFactory::undoFollow       Undo embedding the Follow
          ├─ FederationDeliveryHandler::deliver
          └─ FollowingRepository::remove       only after acceptance
```

The delivery is synchronous because the answer decides the local state: a
refused Follow must not stay in the collection, and a refused Undo must leave
a retryable edge. Posts still queue their delivery; a Follow cannot, because
its handler has to know whether the peer accepted it before it answers.

## Serving an actor

```
GET /@{handle}
  └─ ActorHandler
      └─ ActorDocumentService   ids from ActivityFactory, key from RsaKeyPair
GET /@{handle}/followers[?page=&limit=]
  └─ FollowersCollectionHandler
      └─ ActorDocumentService::followers → FollowerRepository::actorIdsPage
GET /@{handle}/following[?page=&limit=]
  └─ FollowingCollectionHandler
      └─ ActorDocumentService::following → FollowingRepository::actorIdsPage
GET /@{handle}/outbox[?page=&limit=]
  └─ OutboxHandler
      └─ ActorOutboxService → PostRepository::authorPostsPage
GET /.well-known/webfinger
  └─ WebFingerHandler
      └─ WebFingerService → ActivityFactory::actorId
```

All five are disabled (503) unless the host provides a signing key and a
`LocalActorDirectory`; an unknown handle or foreign WebFinger host answers 404
without echoing it. The document's key id is exactly the id the inbox
resolves, its `outbox` URL is exactly the collection id, its `following` URL
is exactly the following collection id, and the WebFinger `self` href is
exactly the document URL, which tests walk end to end.

The three collections use one paging shape. Without `page` the root is an
`OrderedCollection` with `totalItems` and `first`; with `page=1..n` it is an
`OrderedCollectionPage` with `partOf`, its `orderedItems`, and `next`/`prev`
where they exist. Page numbers are 1-based, the default page size is 20, the
maximum is 40, and generated links carry a non-default limit.

## Data invariants

- **UTC, second precision.** `Support\Time` is the only formatter. Stored
  timestamps are fixed-width RFC 3339 strings, which makes lexicographic order
  chronological on SQLite, PostgreSQL and MySQL, and makes a round trip equal
  the value that was written.
- **Stable ids.** A post id never changes; the public URL and the ActivityPub
  object id are derived from it.
- **Soft deletion.** Deleting sets `deleted_at`; every read filters it. The row
  survives so a `Delete` can still be delivered and a later import can see it.
  Remote posts use the same rule.
- **Remote idempotence.** `social_remote_post.activity_id` is the primary key,
  so one signed activity can create at most one row. `object_id` is not unique:
  two actors may boost the same object. `social_attachment.source_url` is
  unique for imported media, so replaying a URL reuses the local row; the
  nullable column keeps local uploads unaffected.
- **Untrusted remote bytes.** Remote media only reaches `MediaService::upload`
  after the guard checked every hop, every resolved address and the bounded
  body. The declared ActivityPub type is checked against the same sniffed type
  as a local upload; a mismatch writes nothing.
- **Private blocks.** `social_block` is unique by (local user, actor) and is
  applied only to that user's home query. A block never deletes a shared remote
  row and never changes another account's follow graph.
- **Schema next to the repository or adapter.** `PostSchema` defines the post
  and post-attachment tables once; `AttachmentSchema`, `RemotePostSchema`,
  `BlockSchema`, `JobSchema` and `ActivityLogSchema` do the same for media
  metadata, remote content, per-user blocks, the durable queue and the replay
  log. The standalone runs those statements; the Nextcloud host creates the
  same columns through its schema API. A dump can therefore move between the
  two.
- **Media metadata and bytes are separate.** A row points at one original and
  one thumbnail key. The service writes both blobs before the row and cleans
  them up if the row fails; the original bytes are never rewritten by the
  thumbnail step. Unreferenced blobs are an operational cleanup concern, not a
  read-time fallback.

## Ports

| Port | Methods | In-memory / test | Production |
| --- | --- | --- | --- |
| `Clock` | `now()` | `FrozenClock` | `SystemClock` |
| `Config` | get/set/delete/has/all, typed getters | `ArrayConfig` | YAML + env + DB |
| `BlobStore` | put/read/exists/delete/size/list | `InMemoryBlobStore` | `LocalBlobStore`, S3 later |
| `ImageProcessor` | `thumbnail()` | `GdImageProcessor` (local) | host replacement such as ImageMagick |
| `Db\Connection` | execute/fetch/transaction/driver | — | `PdoConnection` |
| `Http\HttpClient` | `request()` | `FakeHttpClient` (tests) | `CurlHttpClient` |
| `Http\BoundedHttpClient` | `requestBounded()` body cap and address pinning | `FakeHttpClient`, `LocalHttpClient` | `CurlHttpClient` |
| `Network\HostResolver` | `resolve()` | test host map | `DnsHostResolver` |
| `Jobs\JobQueue` | push/reserve/complete/fail, counts | `InMemoryJobQueue` | `PdoJobQueue` |
| `ActivityLog` | claim/release | `InMemoryActivityLog` | `PdoActivityLog` |

Adding a port is a breaking change for both hosts. Add one only when a host
genuinely owns the capability.

## What is not here yet

| Missing | Where it will attach |
| --- | --- |
| accounts, sessions, password reset | a `Platform\Identity` port and a `User` domain |
| thread/context fetching | `HttpClient` + the remote-post repository; outbox backfill already catches up missed `Create`/`Announce`, but does not walk replies |
| AT Protocol | `Protocol\Atproto`, moved from the app |
| Mastodon-compatible API | more routes in the manifest; handlers over the same services |
| admin settings | host pages; the core keeps the settings *rules* |
| migrations runner | the standalone host, executing `*Schema` statements; Nextcloud uses its own schema API |
| Vue UI | `@alohasocial/ui`; contract is [ADR 0005](adr/0005-shared-ui.md), extraction pending |
| Nextcloud adapter | in `AlohaSocial/social`, implementing `src/Platform`; the concrete mapping is in [the Nextcloud guide](guides/nextcloud-host.md) |
| Nextcloud data import | the `social` host, following the [migration guide](guides/migration-from-nextcloud.md) |

## Testing strategy

- **Unit** for value objects, formatting and validation.
- **Contract tests** — one abstract case run against every implementation of a
  port (`BlobStoreTestCase`, `PostRepositoryTestCase`,
  `AttachmentRepositoryTestCase`, `FollowerRepositoryTestCase`,
  `FollowingRepositoryTestCase`, `RemotePostRepositoryTestCase`,
  `BlockRepositoryTestCase`, `HomeTimelineRepositoryTestCase`,
  `JobQueueTestCase`, `ActivityLogTestCase`). A new adapter is only done when
  it passes the shared case. The PDO queue and log cases also use a real SQLite
  file and a fresh connection, so a restart is part of the contract.
- **Protocol vectors** — a signature is verified by the same code that produced
  it *and* rejected for a changed body, a changed header, a stale date, an
  unsigned digest and a foreign key.
- **End to end** — `Application::inMemory()` is a real object graph; the test
  writes a post over HTTP, drains the queue, and verifies the signature on the
  request that left. `InboundTimelineTest` delivers a signed Create, Announce
  and Delete through the real inbox and reads the result through the
  authenticated home timeline. `RemoteOutboxBackfillTest` exercises paging,
  deduplication and scope limits without a network; `RemoteMediaTest` also
  stores a historical post through the backfill, repairs its media after a
  disabled period, repeats the URL without duplicates and proves that a bad or
  blocked historical image leaves the post available. `ApplicationHostTest`
  repeats the Create-to-home path through `forHost()` with SQL repositories,
  `PdoJobQueue` and `PdoActivityLog`. `MediaTest` uploads a PNG through the
  real route into a temporary `LocalBlobStore`, fetches the original and
  thumbnail, attaches it to a post and reads it back through a post and both
  timelines; it also proves wrong, corrupt and oversized uploads write no blob
  and no post. `RemoteMediaTest` delivers a signed Create containing attachments
  through the real inbox while a local HTTP server answers the guarded fetch;
  it covers redirects, SSRF targets, size, corrupt bytes, a false type, a
  timeout, duplicate URLs and historical backfill media. `CurlHttpClientTest` covers the production
  client's body cap and address pinning against the same fixture server.
