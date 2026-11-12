# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Shared inboxes and delivery batching: every actor document advertises
  `endpoints.sharedInbox`, pointing at the new signed `POST /inbox/shared`
  route. Both inbox routes use the same handler, signature-over-path checks,
  actor ownership, policy and replay guard. Resolved remote actors carry an
  optional shared inbox; a signed Follow stores it in the new nullable
  `social_follower.shared_inbox_url` column. `FollowerRepository::inboxesFor()`
  prefers that URL, falls back to the individual inbox when it is `NULL`, and
  de-duplicates, so two followers on one server produce one delivery job.
  Follow, Undo and Accept keep using the individual inbox. The route manifest
  and OpenAPI export now have 25 routes. Existing follower tables need the
  nullable column (`ALTER TABLE social_follower ADD COLUMN shared_inbox_url
  VARCHAR(512) NULL`); existing rows need no backfill. The actor document, route
  reference, follow graph, inbound federation, database, host integration and
  configuration guides, plus the new shared-inbox guide, document the behaviour,
  fallback and migration. The core schedules no cleanup for this column: it is
  removed with the follower row.
- Thread/context fetching: the authenticated
  `GET /api/v1/remote-posts/{id}/thread` returns the freshly fetched target, its
  ancestors from root to parent and its direct replies for a stored remote
  post. The remote activity id is URL-encoded as one path segment; the reader
  must follow the target actor and must not block it. Ancestors and replies
  from blocked actors are omitted, only direct children are returned, and the
  depth/reply counts are bounded by `social/thread.max_depth` and
  `max_replies`. `RemoteThreadRepository` (in-memory and PDO behind one contract
  test) caches nodes in `social_remote_thread_post` by object id, so repeats and
  shared ancestors do not duplicate rows. `RemoteFetcher` was extracted as the
  shared network guard for remote media and threads (http(s)-only, public
  address checks on every hop, redirect revalidation, timeout and body cap);
  `RemoteMediaFetcher` delegates to it without changing its stable
  `remote_media_*` reasons. `social/thread.fetch`, `thread.max_depth`,
  `thread.max_replies`, `thread.max_bytes`, `thread.timeout_seconds` and
  `thread.max_redirects` are documented. A target failure is `502`; ancestor or
  reply failures are logged and skipped. The route adds one entry to the route
  manifest (24 routes) and the OpenAPI export. `RemoteThreadRepository`
  exposes `deleteFetchedBefore()` for host-scheduled cleanup; the core does not
  schedule it. Thread context does not import media blobs.
- Historical remote media catch-up: `RemoteOutboxBackfill` now passes every
  stored and every already-present `Create`/`Announce` object to the same
  `RemoteMediaService` as the live inbox. A later explicit backfill run can
  repair a historical post whose media was disabled or failed earlier without
  creating a second post; repeated source URLs reuse the local attachment and
  the existing link set survives a repeat in which one candidate disappears.
  All SSRF, redirect, timeout, body-size, MIME, attachment-count and
  `social/media.remote_fetch` rules apply unchanged.
- Remote media ingestion: a signed inbound `Create` or `Announce` can now
  import up to four image attachments from its embedded object after the post
  is stored. The new `RemoteMediaFetcher` enforces http(s)-only URLs, public
  DNS/IP checks on every hop (SSRF), manual redirect revalidation, a per-hop
  timeout and a hard body limit; `CurlHttpClient` implements the bounded and
  pinned connection path. The existing `MediaService` validates the declared
  and sniffed MIME type, dimensions and thumbnail and stores original plus
  thumbnail locally. A nullable unique `source_url` on `social_attachment`
  makes repeated URLs idempotent, and `social_remote_post_attachment` links
  the local rows to the remote post. Failed attachments log documented stable
  reasons and are skipped without discarding the post or leaving half rows or
  blobs. Remote timeline entries now carry alt text and local media URLs.
  `social/media.remote_fetch`, `media.remote_max_bytes`,
  `media.remote_timeout_seconds` and `media.remote_max_redirects` are
  documented; disabling the fetch keeps existing copies readable. The route
  manifest and OpenAPI export are unchanged (remote media adds no route).
- Media pipeline: authenticated `POST /api/v1/media` accepts a multipart image
  with optional alt text, sniffs JPEG/PNG/WebP, enforces size, dimension and
  pixel limits, stores the untouched original and a GD-generated thumbnail in
  a `BlobStore`, and writes metadata to the new `social_attachment` table. A
  public `GET /api/v1/media/{id}` serves the original or
  `?variant=thumbnail`. Posts accept up to four `attachment_ids` owned by their
  author and expose alt text plus fetchable URLs in the post, public-timeline
  and home-timeline shapes; posts without attachments keep the old shape.
  Invalid, corrupt and oversized uploads leave no blob or row. This adds two
  routes, the `AttachmentRepository` contract test and an end-to-end media
  test; 24 routes.
- Remote outbox backfill: `RemoteOutboxBackfill` catches up missed `Create` and
  `Announce` activities from a followed actor's public outbox. It follows
  `first`/`next` only on the outbox origin, is bounded by pages and items,
  skips unsupported or malformed entries, and deduplicates by activity id
  without claiming the id in the replay log. An optional `BlockedActorPolicy`
  refuses a blocked actor before the fetch. `RemotePostFactory` now holds the
  object mapping shared with the inbox.
- OpenAPI export: `Contract\OpenApi`, `bin/export-openapi` and the
  `composer openapi:export` script render the existing route manifest as
  OpenAPI 3.1 with exact paths, methods, operation ids, auth, rate limits and
  path parameters. Payload schemas are deliberately not invented.
- Follow approval: the optional `FollowPolicy` runs after signature
  verification and before the follower repository write. It can refuse a
  Follow for a specific local account with `403 follow_not_accepted`; the
  refusal creates no edge and releases the activity claim so a later retry can
  succeed. `AcceptAllFollows` is the default and keeps the previous behaviour.
- Bounded actor-key cache: `CachingActorResolver` wraps any `ActorResolver`,
  keys entries by the full key id, reuses only validated successes and drops
  entries after a short TTL or when the bounded cache is full. Failed
  resolutions are never cached, so a transient fetch error cannot pin an actor
  as unknown.
- Per-user blocks: `Block`/`BlockRepository` with in-memory and PDO adapters,
  one contract test and the canonical `BlockSchema` (`social_block`).
  `GET`/`POST /api/v1/blocks` and `DELETE /api/v1/blocks/{id}` let an account
  manage its own list; the list is private and idempotent per actor. The
  combined home timeline filters remote posts and boosts by the reader's
  blocks, both in memory and in the SQL union, without deleting shared rows or
  affecting another local account. 21 routes.
- Inbound content: signed `Create`, `Delete` and `Announce` are now processed
  for actors followed by at least one local account. A new
  `RemotePostRepository` (in-memory and PDO, one contract test) stores the
  activity id as the deduplication key, object/actor references, kind,
  content, content warning, published time and soft-delete marker in
  `social_remote_post`. `Delete` removes exactly the matching activity or
  post, and `Announce` is stored and returned as a boost rather than a second
  standalone post.
- Follow-based home timeline: `HomeTimelineRepository` combines the reader's
  own local posts with remote posts/boosts from the outgoing follow graph in
  one ordered page. The SQL adapter performs the union before applying the
  cursor and limit, so pagination stays coherent.
- Inbound content authorization: an actor must be followed by at least one
  local account (`not_followed`), and an optional `BlockedActorPolicy` lets a
  host refuse an actor (`blocked_actor`) without changing the remote-post
  store. Object attribution and Delete ownership are checked against the
  signing actor.
- Attribution guard: `tools/check-attribution.php` scans every commit reachable
  from any ref, including author/committer identities and full message bodies,
  for AI/tool attribution markers. It fails on a match, refuses shallow clones,
  runs in `composer check`, and the CI checkout fetches the full history.
- Cutover validator: `CutoverValidator`, `bin/validate-cutover` and
  `composer migration:validate` read the documented neutral JSON export,
  derive the core's actor, key, note, Create/Delete/Follow/Undo and outgoing
  follow identities, and return a record/field parity report with exit codes
  0/1/2. The validator is read-only and never reads a host database; tests
  cover a passing export and every identity break, including a changed
  `social_following.id` and orphaned references.
- Migration and ownership decisions: ADR 0004 defines a feature-by-feature
  strangler migration, immutable actor/key/post/follow identities, host-owned
  imports and one owner per feature. The Nextcloud migration guide records the
  data mapping, export format, shadow-run checks, cutover gates and rollback.
- Shared UI contract: ADR 0005 defines `@alohasocial/ui` and a narrow
  `HostBridge` for authentication, transport, navigation and capabilities, so
  both hosts can share Vue components without sharing their runtimes.
- Durable queue and replay log: `PdoJobQueue` and `PdoActivityLog` implement
  the existing ports over `Platform\Db\Connection`, with `JobSchema` and
  `ActivityLogSchema` as the canonical DDL. A job survives a fresh connection;
  reserve/complete/fail, retry, dead-letter and replay rejection are tested
  against a SQLite file. `Application::forHost()` now has production-ready
  adapters instead of requiring every host to build its own.
- Host integration: `Application::forHost()` wires the SQL repositories and a
  host's ports into the same kernel, worker and services as the reference
  assembly. The host integration and Nextcloud guides cover Composer setup,
  adapters, URL prefixes, HTTP mapping, migrations and rollout.
- Discovery: `GET /@{handle}` serves the ActivityStreams actor document with
  `preferredUsername`, `name`, `inbox`, `outbox`, `followers` and a
  `publicKey` whose id is exactly the key id inbound verification resolves;
  `GET /@{handle}/followers` serves the `OrderedCollection` of real follower
  actor ids. Both are disabled (503) without a signing key and a local actor
  directory.
- Paged actor collections: followers, following and outbox now serve a root
  `OrderedCollection` with `first` and `OrderedCollectionPage` documents with
  `partOf`, `next`, `prev` and a bounded page size. Repository page queries
  (`actorIdsPage`, `authorPostsPage`, `authorPostCount`) back the pages, so a
  peer can walk large collections without receiving every item at once.
- Outbox: `GET /@{handle}/outbox` serves the `OrderedCollection` of the
  account's public posts as `Create` activities, newest first. Its id is
  exactly the `outbox` URL the actor document advertises, and every item is
  the same document outbound delivery sends.
- WebFinger: `GET /.well-known/webfinger?resource=acct:{handle}@{host}`
  resolves a local handle to the actor document URL and serves it as
  `application/jrd+json` with a single `self` link of type
  `application/activity+json`. The configured host is enforced; foreign hosts
  and unknown handles share one 404 without echoing either.
- Following: `GET /@{handle}/following` serves the `OrderedCollection` of the
  actors a local account follows, newest first. It reads a separate outgoing
  follow repository (`social_following`), so it is not a copy of the
  followers table; the collection id is exactly the `following` URL the actor
  document advertises.
- Local following: `POST /api/v1/following` stores the outgoing edge and
  delivers a signed `Follow` to the supplied inbox; `DELETE
  /api/v1/following/{id}` delivers an `Undo` and removes the edge only after
  the peer accepted it. A refused Follow is rolled back, a refused Undo keeps
  the follow retryable. The outgoing schema now stores the inbox. 18 routes.
- Inbound `POST /inbox`: signature verification against an actor resolved by
  key id, `Follow` → follower edge + queued `Accept`, `Undo(Follow)`, and a
  replay guard. The endpoint stays at 503 without a configured resolver.
- `ActorResolver` / `HttpActorResolver`, `RemoteActor`, `ActivityLog` /
  `InMemoryActivityLog` and `FollowerRepository::removeByActor` as the ports
  and domain pieces behind the inbox.
- Follow graph: `Follower` entity, in-memory and SQL repositories behind one
  contract test, `FollowerSchema`, and `FollowerInboxResolver`.
- `FollowerService` with idempotent follow, per-account isolation and cursor
  pagination.
- Routes `followers.list`, `followers.follow`, `followers.unfollow` (the
  manifest now has 18 routes).
- End-to-end delivery to real followers: a followed actor's inbox receives the
  signed `Create` of the next post.
- Documentation: concept guides, a route reference, an error-code reference, a
  configuration reference, an operations checklist, a roadmap, an FAQ and
  contributor/security/conduct documents.

### Fixed

- A repeated remote-media import no longer drops an already linked local
  attachment when one other advertised URL fails to fetch. Existing links are
  merged with the current successful set instead of replacing them.
- A post with `followers` visibility was readable by anyone who knew its id.
  Single-post reads now apply visibility and answer 404 to non-authors.

### Changed

- The home timeline no longer includes every public local post. It now means
  the reader's own posts (all visibilities) plus remote posts and boosts from
  actors the reader follows; local public posts from accounts outside that
  graph are absent. `GET /api/v1/timelines/home` entries gain `object_id`,
  `object_author_id`, `kind` and `boosted_by`.
- The reference assembly federates to the stored follow graph when an HTTP
  client and a signing key are provided; without them it reports no audience,
  so no delivery is queued that could not be signed.

## [0.1.0] - 2026-11-11

### Added

- Platform ports: clock, configuration, blob storage, SQL connection, HTTP
  client, each with an in-memory and a production adapter.
- Posts and visibility, soft deletion, public and home timelines with stable
  cursor pagination.
- ActivityPub: RSA key pairs, HTTP signatures (sign and verify), `Create` and
  `Delete` documents, audience mapping.
- Delivery queue with retry backoff, dead letters and a worker; signed POSTs.
- HTTP kernel, parameterised router, middleware, one JSON error shape.
- A single route manifest with a JSON export, and a host-neutrality guard.
- AES-256-GCM secret box.
- 284 tests; CI on PHP 8.3, 8.4 and 8.5.
