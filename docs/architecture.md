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
per-user block store, the remote-attachment candidate, the optional
`LocalActorSearch` read capability and the `InboxResolver` seam.
`Domain\Timeline` owns the combined home-timeline read model; `Domain\Post`
keeps the optional `PostSearchRepository` next to the post store.
`Domain\Media` owns attachment metadata, its local post link and its remote
post link; the bytes go through the `BlobStore` port and image processing
through the `ImageProcessor` port. These are domain ports, not platform ports:
the question "where does this audience live?" belongs to the application, only
the inbox URLs are data. A follower edge stores both the individual inbox and,
when advertised, the server's shared inbox.

## Request lifecycle

1. The host authenticates and builds a `Core\Http\Request` with `userId`.
2. `Kernel` asks the `Router` for a `RouteMatch` (404/405 are decided here).
3. The matched route and its path parameters become request attributes.
4. Middleware runs outermost first — `MastodonApiGate` hides the optional
   compatibility routes when their switch is off, `RequireAuthentication`
   refuses a route with `requires_auth` when there is no user, and
   `RequireScopes` checks the token scopes a compatibility route declares.
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
          └─ JobQueue::push (per effective inbox) {"inbox", "activity", "key_id"}
                                              │
JobWorker::runOnce ──────────────────────────┘
  └─ FederationDeliveryHandler
      ├─ json_encode the activity built when the post was written
      ├─ HttpSignature::sign   (request-target) host date digest
      └─ HttpClient::request   non-2xx → retry with backoff, then dead letter
```

The inbox list comes from the follow graph: `FollowerInboxResolver` asks
`FollowerRepository::inboxesFor()` for the author's stored followers. Each row
carries its individual inbox and optional shared inbox, so delivery needs no
network lookup at post time. The shared inbox wins when present, and effective
inbox URLs are de-duplicated before a job is queued; two followers on one
server therefore produce one job. Follow, Undo and Accept keep using the
individual inbox.

The activity is built at write time and stored in the job, not rebuilt at
delivery time: an edit between queueing and sending must not change what a
retry sends. Its ids are derived from the instance URL and the post id, so a
retry is idempotent for the receiving server.

## Receiving an activity

```
POST /inbox  or  POST /inbox/shared
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
          ├─ FollowerService::follow         Follow → edge + shared inbox
          └─ JobQueue::push                  Accept → individual inbox
```

Every refusal is an `InboxRejectedException` with a stable reason; the handler
is the only place that maps a reason to an HTTP status. The inbox is enabled
only when an actor resolver, an HTTP client and a signing key are all present —
otherwise both routes answer 503 instead of pretending. The shared route is
the same handler with the same checks, not a separate trust boundary.

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

## Fetching a remote thread

```
GET /api/v1/remote-posts/{id}/thread
  └─ ShowRemoteThreadHandler
      └─ RemoteThreadService::threadForUser
          ├─ RemotePostRepository::findByActivityId
          ├─ FollowingRepository::find / BlockRepository::findByActor
          ├─ RemoteThreadFetcher::fetch(target object)
          │   └─ RemoteFetcher      shared SSRF/redirect/timeout/body guard
          ├─ RemoteThreadFetcher::fetch(parent) up to thread.max_depth
          ├─ RemoteThreadFetcher::fetchReplies(first page only)
          └─ RemoteThreadRepository::upsert  idempotent object-id cache
```

The target is a stored remote activity, but the fetched nodes are objects: an
ancestor or reply may never have been delivered to this instance. That is why
the cache has its own table keyed by object id and never appears in the home
timeline. Ancestors are returned root-first; replies are direct children only,
filtered by `in_reply_to_id === target.object_id`. The reader's blocks are
applied to the target, every ancestor and every reply. A failure to read one
parent or the reply collection is logged and truncates that part of the view;
a target failure is a `502` because the request has no anchor.

`RemoteFetcher` is shared with remote-media ingestion. It owns the
http(s)-only check, userinfo rejection, public-address check on the initial
host and every redirect, manual redirect following, timeout and body cap.
`RemoteMediaFetcher` adds only the media switch, MIME accept header and the
`remote_media_*` reason prefix. Threads add their own limits, cache and stable
`thread_*` reasons without reimplementing the network guard. The thread cache
stores text/structure only; media is not imported.

## Mastodon compatibility

The public instance description is assembled from configuration and the
stores the host already passes in; it is not an instance database:

```
GET {mastodon.prefix}/instance
  └─ MastodonPresenter::instance
      ├─ InstanceInfo::describe   social/instance.* metadata, read per request
      └─ InstanceInfo::stats
          ├─ LocalActorCount::localActorCount   if the directory supports it
          ├─ PostCount::countLive               if the post repository supports it
          └─ RemoteDomainCount::remoteDomainCount if the remote store supports it
```

A missing capability becomes `0`; the endpoint does not scan all rows or add a
second statistics table. `contact_account` is projected only when it is
configured and the supplied local directory knows the id.

`POST /api/mastodon/v1/statuses` is not a second write path:

```
POST {mastodon.prefix}/statuses
  └─ MastodonCreateStatusHandler  parse JSON/form, map visibility, read Idempotency-Key
      └─ MastodonService::createStatus
          └─ PostService::create   same validation, storage and delivery as /api/v1/posts
              └─ PostRepository::insert
```

Reads project the same `social_post` rows through `MastodonPresenter`; there is
no compatibility table. The deterministic post id (`mastodon-` + hash of user
and key) makes an idempotent retry collide with the existing primary key and
return the stored row. An optional `poll` is validated before `PostService`,
built as a `Domain\Post\Poll`, and inserted in the same post transaction:

```
POST {mastodon.prefix}/statuses  with poll
  └─ MastodonCreateStatusHandler  poll must be an object
      └─ MastodonService::pollFromInput
          ├─ 2–4 PollOption values, 1–50 characters after trim
          ├─ expires_in (300–604800 s) or expires_at, expires_in wins
          └─ CreatePost(poll: Poll)
              └─ PostRepository::insert
                  └─ same transaction: social_post → social_poll → social_poll_option
```

`PdoPostRepository` batches the poll and ordered option rows for a page of
posts instead of querying per status. `MastodonPresenter` projects the Mastodon
poll object and derives `expired` from the injected clock against `expires_at`;
there is no vote row or expiry job, and a status without a poll returns `null`.
The core `Post::toArray()` carries the same optional object for `/api/v1/posts`
and timeline consumers. Media upload reuses the same service as the core route:

```
POST {mastodon.prefix}/media
  └─ MastodonMediaUploadHandler  parse multipart, description/alt_text, Idempotency-Key
      └─ MastodonService::uploadMedia
          └─ MediaService::upload   same validation, thumbnail and cleanup as /api/v1/media
              ├─ BlobStore::put       original + thumbnail
              └─ AttachmentRepository::insert   social_attachment
```

The response is a projection in `MastodonPresenter`, not a second attachment
model. A client idempotency key becomes a deterministic internal `source_url`,
so `MediaService`'s existing source lookup returns the original attachment on a
retry; no idempotency table is added. Metadata updates and deletes stay on the
same row and blobs:

```
PUT {mastodon.prefix}/media/{id}
  └─ MastodonUpdateMediaHandler  JSON/form/multipart description
      └─ MastodonService::updateMedia
          ├─ MediaService::find + ownership + isAttachedToPost
          │   └─ PostRepository::isAttachmentLinked
          └─ MediaService::updateAltText   social_attachment.alt_text only

DELETE {mastodon.prefix}/media/{id}
  └─ MastodonDeleteMediaHandler
      └─ MastodonService::deleteMedia
          ├─ MediaService::find + ownership
          ├─ isAttachedToPost → 422 while a post link exists
          └─ MediaService::discard      row + original + thumbnail
```

`isAttachmentLinked` deliberately counts soft-deleted posts: their link row
survives, so deleting the metadata would leave a dangling reference. The
delete response is the Mastodon empty object `{}` with `200`, and both routes
require `write:media`. Local favourite/unfavourite writes one
`social_favourite` row through `FavouriteRepository`, keyed by the local user
and post, and the presenter batches counts and viewer flags for list reads:

```
POST {mastodon.prefix}/statuses/{id}/favourite
  └─ MastodonFavouriteStatusHandler
      └─ MastodonService::favouriteStatus   visible post + local account check
          └─ FavouriteRepository::insert   social_favourite (user, post) primary key

GET {mastodon.prefix}/timelines/public
  └─ MastodonPresenter::statuses
      └─ FavouriteRepository::countsForPosts / existsForPosts
```

`favourites_count` is public; `favourited` is only true for the authenticated
viewer and is false on the unauthenticated timeline. A repeat favourite is
idempotent through the composite primary key, and unfavourite removes only the
caller's row and is a successful no-op when absent. Notifications are recorded
next to the interaction, not derived at read time:

```
POST {mastodon.prefix}/statuses/{id}/favourite
  └─ MastodonService::favouriteStatus
      └─ NotificationService::recordFavourite(author, actor, post)
          └─ NotificationRepository::insert   social_notification

POST {mastodon.prefix}/accounts/{id}/follow
  └─ LocalRelationshipService::follow
      └─ NotificationService::recordFollow(target, follower)

POST /inbox (Follow)
  └─ InboxService::handleFollow
      └─ NotificationService::recordFollow(local target, remote actor URL)

GET {mastodon.prefix}/notifications
  └─ MastodonNotificationsHandler
      └─ MastodonService::notifications
          ├─ NotificationRepository::find/listFor (unread only)
          └─ MastodonPresenter::notification + status lookup

POST {mastodon.prefix}/notifications/{id}/dismiss
  └─ MastodonDismissNotificationHandler
      └─ MastodonService::dismissNotification
          └─ NotificationService::markRead → read_at

POST {mastodon.prefix}/notifications/clear
  └─ MastodonClearNotificationsHandler
      └─ MastodonService::clearNotifications
          └─ NotificationService::markAllRead → read_at

GET {mastodon.prefix}/notifications/unread_count
  └─ MastodonNotificationUnreadCountHandler
      └─ MastodonService::notificationUnreadCount
          └─ NotificationRepository::countUnread (capped by the service)
```

The notification id is a hash of recipient, type, actor and optional post. It
is the primary key, so repeating an event is a duplicate insert that the
service resolves by re-reading the existing row; there is no random id and no
second idempotency table. The stored row is historical: unfavourite, unfollow
and post deletion do not delete it. A post that no longer exists is omitted
from the projected notification rather than exposing a deleted status. A
remote actor is stored as its URL and projected as a minimal remote account;
the core does not fetch a profile just to render the notification. The local
account directory distinguishes local actor ids from remote URLs. `read_at`
is a nullable durable marker: dismiss marks one owned row, clear marks every
unread row of the recipient, and the list/count read only rows without a
marker. Dismiss is idempotent for the owner and hides a foreign/missing id as
`404`; clear is idempotent and leaves new events unread. Read rows remain
historical and may be pruned by the host.

Local search is a read-only projection over the same stores, not a search
index or a remote resolver:

```
GET {mastodon.prefix}/search?q=…&type=…&offset=…
  └─ MastodonSearchHandler
      └─ MastodonService::search
          ├─ PostSearchRepository::search   live public/unlisted posts, lower(content) LIKE
          │   └─ optional authorIds for following=true
          ├─ LocalActorSearch::search       host's local account directory ids
          └─ MastodonPresenter::account/statuses
```

Status search is case-insensitive, matches only live public/unlisted rows and
uses the stored publish time plus id for stable order; the query's `%` and `_`
are escaped, so they are literal text. No table or index is added. Account
search is optional because a host may not expose a searchable directory; the
route answers `503 search_not_configured` for the affected collection instead
of returning an invented result. `hashtags` is always empty because no hashtag
model exists, and `resolve`/`exclude_unreviewed` have no local effect. Search
pages by `offset`, unlike the cursor-paged timelines.

Local follow/unfollow is a
projection of the same follower graph, not a second relationship table:

```
POST {mastodon.prefix}/accounts/{id}/follow
  └─ MastodonFollowAccountHandler
      └─ MastodonService::followAccount
          └─ LocalRelationshipService::follow
              ├─ LocalActorDirectory        both ids must be local
              └─ FollowerService::follow(local: true)
                  └─ FollowerRepository::insert   social_follower.is_local = 1

GET {mastodon.prefix}/accounts/relationships?id[]=…
  └─ MastodonRelationshipsHandler
      └─ LocalRelationshipService::relationships
          ├─ FollowerRepository::findByActor      following / followed_by
          └─ BlockRepository::findByActor         blocking
```

`FollowerRepository::inboxesFor()` excludes local rows, so this path queues no
remote delivery and sends no signed `Follow`; `LocalRelationshipService`
combines the follower edge with the outgoing graph for `followers_count` and
`following_count`. The public following collection adds local actor ids to its
outgoing page, while the followers collection already reads the follower
graph. `LocalFollowHomeTimelineRepository` decorates the existing home
repository: it fetches the base top page plus the top page of locally followed
authors, applies the same cursor, sort and limit, and delegates unchanged when
there are no local follows. `MastodonApiGate` answers
`404 mastodon_api_disabled` before auth while the switch is off. The prefix is
validated against `/api/v1` and the protocol paths at boot. Timeline and
notification pagination uses the id-aware cursor on `PostRepository` or
`NotificationRepository`; search uses a clamped `offset`. See
[Mastodon-compatible API](guides/mastodon-api.md).

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
      └─ ActorDocumentService::following
          ├─ FollowingRepository::actorIdsPage     remote outgoing edges
          └─ LocalRelationshipService::localFollowingActorIds
              └─ FollowerRepository local edges (is_local = 1)
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
  and post-attachment tables once; `PollSchema`, `FavouriteSchema`,
  `NotificationSchema`, `AttachmentSchema`, `RemotePostSchema`, `BlockSchema`,
  `AtprotoPublicationSchema`, `JobSchema` and `ActivityLogSchema` do the same
  for favourites, notifications, media metadata, remote content, per-user
  blocks, AT Protocol publications, the durable queue and the replay log. The standalone runs those statements; the Nextcloud host creates
  the same columns through its schema API. A dump can therefore move between
  the two.
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

AT Protocol has four domain-level host ports instead of new platform methods:
`AtprotoAccountResolver` returns a linked account, `AtprotoPublisher` writes
one record, `AtprotoDeleter` removes it again and `AtprotoSessionStore` holds
the host-owned OAuth session. `XrpcAtprotoPublisher` is the default adapter for
both record transports over `HttpClient`; fakes cover the network in tests.
Account linking, authorization-code/PAR, refresh-token revocation and PDS
credentials remain host-owned, so remote delete can only act on an account the
host resolves.

OAuth session refresh is a protocol operation, not a route:

```
AtprotoSessionService::current(localUserId)
  └─ AtprotoSessionStore::find
      └─ AtprotoSessionRefresher::refresh when inside the 60-second leeway
          └─ XrpcAtprotoSessionRefresher
              ├─ DpopProofProvider    fresh ES256 proof, optional nonce
              ├─ HttpClient           form-encoded POST to tokenEndpoint
              └─ AtprotoOAuthSession  validate rotated tokens and sub
          └─ AtprotoSessionStore::save
```

The store is a host port because tokens are credentials; the core has no
session table. `InMemoryAtprotoSessionStore` is the test/tool adapter. The
refresher retries exactly one `use_dpop_nonce` challenge and never logs a token
or response body. The existing `XrpcAtprotoPublisher` still uses its bearer
account token; OAuth-bound resource requests remain part of the external
account-linking/client work.

Adding a port is a breaking change for both hosts. Add one only when a host
genuinely owns the capability.

## What is not here yet

| Missing | Where it will attach |
| --- | --- |
| accounts, sessions, password reset | a `Platform\Identity` port and a `User` domain |
| AT Protocol account linking, authorization-code/PAR/revocation, full repository/AppView client, media and firehose | host/app; the core already owns the publishing foundation and OAuth session refresh in `Protocol\Atproto` and `Domain\Atproto` |
| Full Mastodon server API (OAuth/token issuance, remote timelines, favourite federation, boosts, remote notifications, streaming, remote search, account/admin and remote relationship APIs) | host/integration work over the local compatibility subset, including media management, local favourites, local notifications with dismiss/unread state, local follow/unfollow, relationship reads, local poll creation, instance metadata/statistics and local status/actor search; narrower local account/profile endpoints remain on the roadmap; see [Mastodon-compatible API](guides/mastodon-api.md) |
| admin settings | host pages; the core keeps the settings *rules* |
| migrations runner | the standalone host, executing `*Schema` statements; Nextcloud uses its own schema API |
| Vue UI | `@alohasocial/ui`; contract is [ADR 0005](adr/0005-shared-ui.md), extraction pending |
| Nextcloud adapter | in `AlohaSocial/social`, implementing `src/Platform`; the concrete mapping is in [the Nextcloud guide](guides/nextcloud-host.md) |
| Nextcloud data import | the `social` host, following the [migration guide](guides/migration-from-nextcloud.md) |

## Testing strategy

- **Unit** for value objects, formatting and validation.
- **Contract tests** — one abstract case run against every implementation of a
  port (`BlobStoreTestCase`, `PostRepositoryTestCase` (also covers the
  optional `PostSearchRepository`), `AttachmentRepositoryTestCase`,
  `FollowerRepositoryTestCase`, `FollowingRepositoryTestCase`,
  `RemotePostRepositoryTestCase`, `RemoteThreadRepositoryTestCase`,
  `BlockRepositoryTestCase`, `HomeTimelineRepositoryTestCase`,
  `NotificationRepositoryTestCase`, `AtprotoPublicationRepositoryTestCase`,
  `AtprotoSessionStoreTestCase`,
  `JobQueueTestCase`, `ActivityLogTestCase`). A new adapter is only done when
  it passes the shared case. The PDO queue and log cases also use a real SQLite
  file and a fresh connection, so a restart is part of the contract.
- **Protocol vectors** — a signature is verified by the same code that produced
  it *and* rejected for a changed body, a changed header, a stale date, an
  unsigned digest and a foreign key.
- **End to end** — `Application::inMemory()` is a real object graph; the test
  writes a post over HTTP, drains the queue, and verifies the signature on the
  request that left. AT Protocol publishing follows the same pattern: a real
  post and job, a fake XRPC adapter, and the resulting publication row. The
  delete path starts at the real post-delete route, queues `atproto.delete`,
  sends `deleteRecord`, removes the row and retries after a PDS failure. OAuth
  refresh is tested at the protocol boundary with a real ES256 DPoP proof and a
  fake HTTP token endpoint, plus a service test that rotates only an expiring
  session. `InboundTimelineTest` delivers a signed Create, Announce
  and Delete through the real inbox and reads the result through the
  authenticated home timeline. `ApplicationTest` proves that two followers on
  one server sharing an advertised inbox produce one signed job to the shared
  route, and `InboxTest` delivers a signed Follow to `/inbox/shared` and reads
  the stored edge back. `RemoteOutboxBackfillTest` exercises paging,
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
  timeout, duplicate URLs and historical backfill media. `RemoteThreadTest`
  requests a real local thread fixture through the authenticated route and
  covers ordered ancestors, direct replies, persistence, repeat idempotence,
  depth/reply limits, auth, unfollowed/deleted/blocked targets, disabled
  fetching, an invalid target, a blocked ancestor, an SSRF ancestor and
  oversized/slow targets. `CurlHttpClientTest` covers the production client's
  body cap and address pinning against the same fixture server.
  `MastodonApiTest` drives the optional compatibility routes through the same
  kernel, including JSON/form writes, poll creation (options, both expiry
  forms, expiry flip, validation with no partial rows, idempotent retry),
  media upload/update/delete, local follow/unfollow and relationship reads,
  local search, scopes, disabled-before-auth, cursor/offset pagination,
  idempotency and media projection; `ApplicationHostTest` repeats the
  idempotent write, poll persistence and a SQL search over SQL repositories.
  `MastodonCompatibilityTest` covers the prefix, scope and route metadata.
  `FollowerRepositoryTestCase` covers the local edge and its exclusion from
  delivery; the post contract now also covers poll round-tripping, soft-delete
  retention and `PostSearchRepository` visibility, author filtering and offset
  paging.
