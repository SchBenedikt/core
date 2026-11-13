# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- AT Protocol authorization-code/PAR/revocation client:
  `AtprotoOAuthMetadataResolver` discovers the PDS protected-resource document
  and validates the authorization-server metadata (issuer binding, exactly one
  authorization server, PAR, issuer response parameter, client metadata,
  authorization code, refresh token, S256 PKCE, `none` client authentication,
  ES256 DPoP and the `atproto` scope). `XrpcAtprotoOAuthClient` sends the PAR
  request with PKCE S256 and `dpop_jkt`, returns the authorization URL plus the
  state/verifier/DPoP nonce the host must persist by state, exchanges the code
  after checking the callback `iss` and the token `sub`, and revokes the
  refresh token. Both token operations retry one `use_dpop_nonce` challenge
  and never put tokens, codes or response bodies in an exception.
  `DpopKeyThumbprint` is an optional proof-provider capability;
  `Es256DpopProofProvider` implements it with the RFC 7638 thumbprint. The
  browser callback, flow store, credential storage, PDS selection and
  account-linking UI remain host-owned; there is no core route, table or
  migration. Tests cover metadata discovery/issuer binding, the PAR form,
  PKCE/`dpop_jkt`, nonce retries, code exchange, issuer/DID rejection and
  revocation against fake HTTP and DPoP adapters. See
  `docs/guides/atproto.md`.
- Mastodon-compatible local account profiles and follow lists:
  `GET {mastodon.prefix}/accounts/{id}` returns the minimal account shape for
  one local id (`120/60`), while `GET {mastodon.prefix}/accounts/{id}/followers`
  and `GET {mastodon.prefix}/accounts/{id}/following` return paged account
  arrays (`60/60`, default 40, maximum 80). All three are public, additively
  mounted under the configurable prefix and remain behind the
  disabled-by-default Mastodon gate. Unknown and non-local ids are `404
  not_found`; the follower list maps stored local actor URLs back to local user
  ids, and the following list merges outgoing remote edges with local-to-local
  edges by their stored follow time. The lists support `max_id`,
  `since_id`/`min_id` and `limit`, return a `Link` next page, reject a cursor
  outside the requested collection with `404` and reject `max_id` combined with
  `since_id`/`min_id` with `422 validation_failed`; an assembly without the
  relationship service answers `503 follows_not_configured`. Two additive
  repository reads (`FollowerRepository::localFollowsByActor` and
  `FollowingRepository::followedActors`) reuse the existing tables, so there is
  no new table, column, backfill or cleanup job. The manifest/OpenAPI export now
  has 49 routes (23 under the compatibility prefix). Tests cover the in-memory
  and PDO repository contracts, real-kernel profiles/lists, local actor-URL
  mapping, remote target projection, cursor/limit edges and the disabled
  feature. See `docs/guides/mastodon-api.md`.
- Mastodon-compatible notification dismissal and unread state:
  `POST {mastodon.prefix}/notifications/{id}/dismiss` and `POST
  {mastodon.prefix}/notifications/clear` set a nullable `read_at` marker on
  the existing `social_notification` row and return `200 {}`; `GET
  {mastodon.prefix}/notifications/unread_count` returns the capped count
  (`limit` 100 by default, 1000 maximum) with the list's type/account filters.
  `GET {mastodon.prefix}/notifications` now returns only unread rows and adds
  `"unread": true`. Dismiss is idempotent for the caller's own id, a
  foreign/missing id is `404 not_found`, and clear is an idempotent no-op;
  events recorded after clear are unread. The routes require
  `write:notifications` (writes) or `read:notifications` (count), declare
  `30/900` or `60/60` rate limits and stay behind the disabled-by-default
  Mastodon gate. Existing hosts add `read_at VARCHAR(20) NULL` and the
  `(recipient_id, read_at, created_at, id)` index; old rows remain unread and
  there is no backfill or new table. The manifest/OpenAPI export now has 46
  routes (20 under the compatibility prefix). Tests cover the in-memory and
  PDO repository contract, real-kernel HTTP behavior, SQL persistence,
  idempotency, foreign/missing ids, filters/caps, auth, scopes, disabled
  feature and storage failure. See `docs/guides/mastodon-api.md`.
- Mastodon-compatible instance metadata and local statistics:
  `GET {mastodon.prefix}/instance` and the core `GET /api/v1/instance` now
  expose configurable description, version, contact email/account,
  registration/approval/invite flags, languages and thumbnail from the
  existing `social/instance.*` keys. `stats` reports real `user_count`,
  `status_count` and `domain_count` when the supplied directory/post/remote
  stores implement the new optional `LocalActorCount`, `PostCount` and
  `RemoteDomainCount` capabilities; a missing capability reports `0` instead
  of scanning or inventing data. A configured contact account is hidden when
  a supplied local directory does not know it. The in-memory and PDO local
  post repositories and both remote-post repositories implement the counts,
  including live-row filtering and distinct domains. There is no new table,
  migration or cleanup job. Tests cover configured values, defaults, stale
  contacts, the in-memory and SQL projections and the repository contracts.
  See `docs/guides/mastodon-api.md`.
- Mastodon-compatible local poll creation: `POST {mastodon.prefix}/statuses`
  accepts an optional Mastodon `poll` object with two to four options, each
  1–50 characters after trimming, and either `expires_in` (300–604800 seconds)
  or `expires_at`; `multiple`/`hide_totals` accept booleans and form values.
  The poll is stored atomically with its post in the new `social_poll` and
  ordered `social_poll_option` tables from `PollSchema`, validated before
  `PostService`, and returned through `MastodonPresenter` as the Mastodon poll
  entity with zero local vote counts, `voted: false`, empty `own_votes`/`emojis`
  and a clock-derived `expired` flag. Status reads, timelines, account statuses
  and search project the same object; an idempotent retry reuses the original
  post and does not duplicate poll rows. Invalid options, expiry or booleans
  are `422 validation_failed` and create no partial status. The instance
  endpoint now advertises the real `polls` limits. Existing hosts must create
  the two tables before the repository reads polls or a poll is accepted; a
  fresh host gets them from `PollSchema::create()`. Voting, remote polls and
  ActivityPub `Question` federation remain external. See
  `docs/guides/mastodon-api.md`.
- Mastodon-compatible local search: `GET {mastodon.prefix}/search` requires
  `read:search`, declares a `60/60` rate limit and returns the Mastodon v2
  `accounts`/`statuses`/`hashtags` object. It searches only local data:
  case-insensitive matches over live public/unlisted `social_post.content` and
  over the host's local account directory. `type` accepts `accounts`,
  `statuses` or `hashtags`; `hashtags` is always empty because the core has no
  hashtag model. `limit` is 1–40 with a default of 20, `offset` is 0–1000, and
  `following=true` limits both collections to local accounts the caller
  follows. `resolve`/`exclude_unreviewed` are accepted but have no local
  effect. Two optional read ports over the existing stores keep this additive:
  `PostSearchRepository` (implemented by the in-memory and PDO post
  repositories) and `LocalActorSearch` (implemented by the in-memory directory).
  A missing capability answers `503 search_not_configured`; there is no search
  table or index migration. The route manifest and OpenAPI export now have 43
  routes. Remote search, hashtag search, notification dismissal/unread state
  and narrower local account endpoints remain follow-ups; OAuth/token
  issuance, remote timelines/delivery, streaming and admin/remote relationship
  APIs remain external. See `docs/guides/mastodon-api.md`.
- Mastodon-compatible local notifications: `GET
  {mastodon.prefix}/notifications` requires `read:notifications`, declares a
  `60/60` rate limit and returns the signed-in local account's favourites and
  follows, newest first. A local favourite, a local follow and a verified
  inbound remote Follow each record one event; a deterministic id derived from
  recipient, type, actor and post makes repeats idempotent. `types[]` and
  `exclude_types[]` filter the known local types before the limit, and
  `max_id`/`since_id`/`min_id` use the notification id with a time tie-break
  and a `Link` header. A favourite carries the projected status; a follow and
  a favourite whose post was deleted omit it. A local actor is projected as a
  local account, while a remote follower is stored as its actor URL and
  projected without fetching a profile. The new `social_notification` table
  comes from `NotificationSchema` with in-memory and PDO repositories behind
  one contract test; existing hosts must create it before enabling the route,
  with no backfill. Recording is best-effort: a missing table or failed
  notification write is logged without failing the primary favourite/follow
  write, and repeating the event after the migration can heal the row.
  Notifications are historical and survive unfavourite, unfollow and post
  deletion; they are not marked read. The route manifest and
  OpenAPI export had 42 routes at that point. The external Mastodon remainder
  (OAuth, remote timelines/delivery, favourite federation, boosts, remote
  notifications, streaming and remote admin) stays out of scope; narrower local
  account endpoints and notification dismissal/unread state remain core-side
  roadmap follow-ups. See `docs/guides/mastodon-api.md`.
- Mastodon-compatible local follows and relationships: `POST
  {mastodon.prefix}/accounts/{id}/follow` and `.../unfollow` require
  `write:follows`, declare a `30/900` rate limit and return the normal
  relationship shape. Both are idempotent; a self-follow is `422` and an
  unknown local target is `404`. `GET {mastodon.prefix}/accounts/relationships`
  requires `read:follows`, accepts a scalar `id` or `id[]`, de-duplicates,
  limits the request to 100 ids and answers `404` for an unknown target.
  Local follows are stored in the existing `social_follower` graph with
  `is_local = 1`, so follower counts, the followers collection and both
  relationship directions share one source of truth; `inboxesFor()` excludes
  those rows, so no remote delivery or signed `Follow` is queued. The local
  following collection now combines local actor ids with remote outgoing
  edges, and the authenticated home timeline includes the followed account's
  live local posts (blocked accounts stay hidden). The route manifest and OpenAPI export have 41 routes. Existing hosts
  must add `social_follower.is_local SMALLINT NOT NULL DEFAULT 0` through their
  migration path; existing rows stay remote and need no backfill. The rest of
  the Mastodon server API, including following a remote actor through this
  prefix and remote relationship APIs, stays external; see
  `docs/guides/mastodon-api.md`.
- Mastodon-compatible media management: `PUT {mastodon.prefix}/media/{id}`
  updates an unattached attachment's description from JSON, form or multipart
  input and returns the usual Mastodon attachment shape. `DELETE
  {mastodon.prefix}/media/{id}` removes an owned, unattached row and both
  local blobs and answers the Mastodon empty object with status `200`. Both
  routes require `write:media` and declare a `30/900` rate limit; a foreign or
  missing id is `404`, while an attachment already linked to a post is a `422`
  for deletion and `404` for metadata update, matching Mastodon's unattached
  rule. The shared multipart parser is reused for field-only requests, and an
  absent `description` leaves the current value unchanged while an empty one
  clears it. No new table or migration is added: the existing
  `social_attachment` row is updated or removed with its original and
  thumbnail blobs. The route manifest and OpenAPI export now have 38 routes.
  The remaining Mastodon server API surface stays external; see
  `docs/guides/mastodon-api.md`.
- Mastodon-compatible local favourites: `POST {mastodon.prefix}/statuses/{id}/favourite`
  and `.../unfavourite` require `write:favourites`, declare a `30/900` rate
  limit and return the normal Mastodon status shape. A repeat favourite is
  idempotent and unfavouriting an absent row is a no-op; only the caller's row
  can be removed. `MastodonPresenter` now reports the public
  `favourites_count` and a viewer-specific `favourited` flag, using batched
  count/exists queries for timelines. The new `social_favourite` table comes
  from `FavouriteSchema` and is implemented by in-memory and PDO repositories
  behind one contract test; a host migration must create the table, with no
  backfill. An assembly without a `FavouriteRepository` answers `503
  favourites_not_configured`. The route manifest and OpenAPI export include
  these routes. Favourite federation (outbound `Like`/`Undo`, remote counts) and
  the rest of the full Mastodon server API remain external; see
  `docs/guides/mastodon-api.md`.
- Mastodon-compatible media upload: `POST {mastodon.prefix}/media` accepts the
  same `multipart/form-data` envelope as `POST /api/v1/media`, including the
  `file` field and an optional Mastodon `description` (`alt_text` is also
  accepted). It requires `write:media`, declares a `20/900` rate limit and
  returns the Mastodon attachment shape with `id`, `type`, original and
  thumbnail URLs, dimensions, description and metadata. The write goes through
  the existing `MediaService`, so JPEG/PNG/WebP validation, the 5 MiB/40
  megapixel limits, thumbnail generation and cleanup on failure are identical
  to the core route; a rejected upload leaves no `social_attachment` row and
  no blob. An optional `Idempotency-Key` maps to a deterministic internal
  source key, so a retry returns the original attachment without a duplicate
  row or blob; no new table or migration is added for media. The route
  manifest and OpenAPI export include this route. The rest of the full
  Mastodon server API (OAuth/token issuance, remote timelines, favourite
  federation, boosts, notifications, streaming, polls, search and the
  account/admin/relationship surface) remains external; see
  `docs/guides/mastodon-api.md`.
- AT Protocol OAuth session refresh: `AtprotoOAuthSession` validates the token
  endpoint, client id, DID, rotating access/refresh tokens, mandatory `atproto`
  scope and expiry. `AtprotoSessionService` refreshes inside a 60-second
  leeway, saves the rotated pair through the host-owned
  `AtprotoSessionStore`, and leaves the old session untouched on failure.
  `XrpcAtprotoSessionRefresher` sends the form-encoded refresh grant to the
  authorization server and retries exactly one `use_dpop_nonce` challenge;
  `Es256DpopProofProvider` produces a fresh signed DPoP proof per request.
  Tokens remain in the host's secret store, so there is no core session table
  or migration. Account linking, the authorization-code/PAR flow, revocation
  and the full repository/AppView stack remain external; see
  `docs/guides/atproto.md`.
- AT Protocol remote delete: a local post deletion now queues a durable
  `atproto.delete` job for a record this core published. The new
  `AtprotoDeleter` port is implemented by `XrpcAtprotoPublisher` through an
  authenticated `POST /xrpc/com.atproto.repo.deleteRecord`; a missing record
  (404) counts as already deleted. `AtprotoPublishService::scheduleDelete()`
  only queues when a publication row exists, and `AtprotoDeleteHandler` removes
  that row only after the PDS accepts the delete. A PDS failure keeps the row
  for the normal retry backoff, while a deleted-before-publish post queues no
  delete. No table or migration is added; the existing
  `social_atproto_publication` row is the idempotency record. Account linking,
  the authorization-code/PAR/revocation flow, a full repository/AppView
  client, CAR/MST, media, firehose and the Nextcloud extraction remain
  external; OAuth session refresh is covered by the newer entry above. See
  `docs/guides/atproto.md`.
- Mastodon-compatible API (local subset): an additive, disabled-by-default
  compatibility surface under `social/mastodon.prefix` (default
  `/api/mastodon/v1`) with seven routes: instance, public timeline, status
  read/create/delete, local account statuses and verify credentials. It is a
  projection over the existing `social_post` rows and `PostService`, not a
  second status store. Auth and scope metadata (`write:statuses`,
  `read:accounts`), `max_id`/`since_id`/`min_id` pagination with an id
  tie-break and `Link` headers, form and JSON writes, content warnings, media
  ids, and an `Idempotency-Key` that maps to a deterministic post id are
  included. `social/mastodon.api` defaults to `false`; while off, every
  compatibility route answers `404 mastodon_api_disabled`, including
  authenticated writes, because the gate runs before auth and scopes. The
  prefix is validated at boot and must not overlap `/api/v1` or the reserved
  protocol paths. The route manifest and OpenAPI export now have 33 routes
  (tag `mastodon`, `x-required-scopes`); no schema or migration is added.
  OAuth/token issuance, remote timelines, favourites, boosts, notifications,
  streaming, media upload, polls, search and the full account/admin and
  relationship surface remain external; see `docs/guides/mastodon-api.md`.
- AT Protocol publishing foundation: the core now owns ATProto account/record
  primitives, deterministic TIDs encoded as valid `base32-sortable` record
  keys, the authenticated XRPC `com.atproto.repo.putRecord` adapter, the
  `AtprotoAccountResolver`/`AtprotoPublisher` host ports and an idempotent
  `social_atproto_publication` table with in-memory and PDO adapters behind one
  contract test. Public text-only posts are scheduled after the local write
  when `social/atproto.publish` is enabled and an account is linked; the
  explicit authenticated `POST /api/v1/posts/{id}/atproto` returns the stored
  publication and is disabled by default. Non-public, attachment and over-limit
  posts are refused with a stable reason; a PDS failure writes no publication
  row, and a retry uses the same rkey so `putRecord` is an upsert rather than a
  duplicate. The route manifest and OpenAPI export now have 26 routes.
  Accounts and the OAuth authorization-code flow, linked-account storage,
  media, AppView/CAR/MST/firehose and the extraction from the Nextcloud app
  remain external; OAuth session refresh and remote delete are documented in
  the newer entries above. See `docs/guides/atproto.md`. The new table is created from
  `AtprotoPublicationSchema::statements()`; hosts that do not create it must
  not enable the feature.
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
