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

`Domain\Federation` holds the follow graph and the `InboxResolver` seam. It is
a domain port, not a platform port: the question "where does this audience
live?" belongs to the application, only the inbox URLs are data.

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
host's `Config`, `Connection`, `HttpClient`, `RsaKeyPair`, `LocalActorDirectory`,
durable `JobQueue` and `ActivityLog`, builds the PDO repositories and services,
and returns the same `Kernel` the reference assembly uses. The core supplies
`PdoJobQueue` and `PdoActivityLog` for the last two over that same connection.
`Application::inMemory()` remains the test/demo path; both go through one
private assembly, so the two cannot drift apart. The host still owns
authentication, migrations, the HTTP controller and the worker process. See
[using the core from a host](guides/host-integration.md).

## Writing a post (the implemented vertical slice)

```
POST /api/v1/posts
  └─ CreatePostHandler        parse and validate the shape
      └─ PostService::create  trim, length, content warning, visibility
          ├─ PostRepository::insert        one row, second-precision UTC
          ├─ InboxResolver::inboxesFor     empty until the follow graph exists
          └─ JobQueue::push (per inbox)    {"inbox", "activity", "key_id"}
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
      └─ InboxService      signature → actor → replay → apply
          ├─ ActorResolver::resolveByKeyId   (HTTP or fixture)
          ├─ HttpSignature::verify           raw body, signed headers
          ├─ ActivityLog::claim              replay guard
          ├─ FollowerService::follow         Follow → edge
          └─ JobQueue::push                  Accept → follower inbox
```

Every refusal is an `InboxRejectedException` with a stable reason; the handler
is the only place that maps a reason to an HTTP status. The inbox is enabled
only when an actor resolver, an HTTP client and a signing key are all present —
otherwise it answers 503 instead of pretending.

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
GET /@{handle}/followers
  └─ FollowersCollectionHandler
      └─ ActorDocumentService::followers → FollowerRepository::actorIds
GET /@{handle}/following
  └─ FollowingCollectionHandler
      └─ ActorDocumentService::following → FollowingRepository::actorIds
GET /@{handle}/outbox
  └─ OutboxHandler
      └─ ActorOutboxService → PostRepository::authorPosts
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

## Data invariants

- **UTC, second precision.** `Support\Time` is the only formatter. Stored
  timestamps are fixed-width RFC 3339 strings, which makes lexicographic order
  chronological on SQLite, PostgreSQL and MySQL, and makes a round trip equal
  the value that was written.
- **Stable ids.** A post id never changes; the public URL and the ActivityPub
  object id are derived from it.
- **Soft deletion.** Deleting sets `deleted_at`; every read filters it. The row
  survives so a `Delete` can still be delivered and a later import can see it.
- **Schema next to the repository or adapter.** `PostSchema` defines the post
  table once; `JobSchema` and `ActivityLogSchema` do the same for the durable
  queue and replay log. The standalone runs those statements; the Nextcloud
  host creates the same columns through its schema API. A dump can therefore
  move between the two.

## Ports

| Port | Methods | In-memory / test | Production |
| --- | --- | --- | --- |
| `Clock` | `now()` | `FrozenClock` | `SystemClock` |
| `Config` | get/set/delete/has/all, typed getters | `ArrayConfig` | YAML + env + DB |
| `BlobStore` | put/read/exists/delete/size/list | `InMemoryBlobStore` | `LocalBlobStore`, S3 later |
| `Db\Connection` | execute/fetch/transaction/driver | — | `PdoConnection` |
| `Http\HttpClient` | `request()` | `FakeHttpClient` (tests) | `CurlHttpClient` |
| `Jobs\JobQueue` | push/reserve/complete/fail, counts | `InMemoryJobQueue` | `PdoJobQueue` |
| `ActivityLog` | claim/release | `InMemoryActivityLog` | `PdoActivityLog` |

Adding a port is a breaking change for both hosts. Add one only when a host
genuinely owns the capability.

## What is not here yet

| Missing | Where it will attach |
| --- | --- |
| accounts, sessions, password reset | a `Platform\Identity` port and a `User` domain |
| inbound `Create`/`Delete`/`Announce`, fetching remote posts | `InboxService` + a remote post store |
| Paged collections | the existing `actors.*` handlers and repositories |
| a follow-based home timeline | `FollowingService` + `TimelineService` |
| media pipeline | `BlobStore` + a `Media` service, ffmpeg outside the core |
| AT Protocol | `Protocol\Atproto`, moved from the app |
| Mastodon-compatible API | more routes in the manifest; handlers over the same services |
| admin settings | host pages; the core keeps the settings *rules* |
| migrations runner | the standalone host, executing `*Schema` statements |
| Vue UI | `@alohasocial/ui` with a `HostBridge` per host |
| Nextcloud adapter | in `AlohaSocial/social`, implementing `src/Platform`; the concrete mapping is in [the Nextcloud guide](guides/nextcloud-host.md) |

## Testing strategy

- **Unit** for value objects, formatting and validation.
- **Contract tests** — one abstract case run against every implementation of a
  port (`BlobStoreTestCase`, `PostRepositoryTestCase`,
  `FollowerRepositoryTestCase`, `FollowingRepositoryTestCase`,
  `JobQueueTestCase`, `ActivityLogTestCase`). A new adapter is only done when
  it passes the shared case. The PDO queue and log cases also use a real SQLite
  file and a fresh connection, so a restart is part of the contract.
- **Protocol vectors** — a signature is verified by the same code that produced
  it *and* rejected for a changed body, a changed header, a stale date, an
  unsigned digest and a foreign key.
- **End to end** — `Application::inMemory()` is a real object graph; the test
  writes a post over HTTP, drains the queue, and verifies the signature on the
  request that left. `ApplicationHostTest` repeats the important paths through
  `forHost()` with SQL repositories, `PdoJobQueue`, `PdoActivityLog` and a fake
  inbox.
