# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
