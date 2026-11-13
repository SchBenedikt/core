# Roadmap

Status of the core, not a promise about dates.

## Done

- Platform ports: clock, config, blob storage, SQL, HTTP client — with in-memory
  and production adapters.
- Posts: validation, visibility, soft deletion, single-post access control.
- Timelines: public and home, stable cursor pagination.
- ActivityPub: RSA keys, HTTP signatures (sign and verify), `Create`/`Delete`
  documents, audience mapping.
- Delivery: durable queue contract, retries with backoff, dead letters,
  signed POSTs, idempotent activity ids. `PdoJobQueue` and `PdoActivityLog`
  provide the durable SQL implementations.
- Follow graph: follower storage (in-memory and SQL), inbox resolution, three
  endpoints, isolated per account.
- Shared inboxes and delivery batching: every actor document advertises
  `endpoints.sharedInbox`, and `POST /inbox/shared` accepts the same signed
  activities as `POST /inbox`. A peer's advertised shared inbox is stored on
  the follower edge in nullable `shared_inbox_url`; delivery de-duplicates by
  that URL, so two followers on one server receive one post job. Follow, Undo
  and Accept keep using the individual inbox, and rows without a shared inbox
  fall back unchanged. See [shared inboxes](guides/shared-inboxes.md).
- Inbound federation: `POST /inbox` with signature verification, actor-key
  resolution over a port, `Follow` → edge + `Accept`, `Undo(Follow)`, replay
  guard, and signed `Create`/`Delete`/`Announce` from followed remote actors.
- Remote posts and boosts: `RemotePost`/`RemotePostKind`, a canonical
  `RemotePostSchema` (`social_remote_post`), in-memory and PDO repositories,
  activity-id deduplication and soft deletion. Announce rows are boosts, not
  standalone posts.
- Follow-based home timeline: `HomeTimelineRepository` combines the reader's
  own local posts with remote posts/boosts from the outgoing follow graph and
  applies one cursor and limit after sorting.
- Inbound content authorization: an actor must be followed by at least one
  local account, an optional host `BlockedActorPolicy` can refuse an actor,
  and an object/Delete ownership mismatch is refused without changing state.
- Actor-key cache: `CachingActorResolver` wraps the HTTP resolver, keys by the
  full key id, caches only successful resolutions and bounds entries by a short
  TTL and a maximum size. A cached entry still came from the full identity
  check; failures are not cached.
- Follow approval: the optional `FollowPolicy` decides whether a verified
  inbound Follow creates an edge for a local account. A refusal is
  `follow_not_accepted`, writes nothing and releases the replay claim; the
  default `AcceptAllFollows` preserves the previous behaviour.
- OpenAPI export: `bin/export-openapi` renders the route manifest as OpenAPI
  3.1, including paths, methods, operation ids, auth, rate limits and path
  parameters. It does not invent payload schemas the manifest does not have.
- Remote outbox backfill: `RemoteOutboxBackfill` catches up missed `Create` and
  `Announce` activities from a followed actor's public outbox. It is bounded by
  pages and items, stays on the outbox origin, skips unsupported or malformed
  entries and deduplicates by activity id. Thread/context fetching is a
  separate, explicit read path, not a mirror of the outbox.
- Media: `Attachment` metadata with JPEG/PNG/WebP validation, an
  `AttachmentRepository` with in-memory and PDO adapters behind one contract
  test, `BlobStore` storage of an untouched original and a GD-generated
  thumbnail, authenticated multipart upload, public original/thumbnail fetch
  and up to four attachments on a post. Alt text and URLs are included in the
  post and both timeline shapes without changing posts that have no
  attachments. Invalid, corrupt and oversized uploads leave no blob and no
  row.
- Remote media: images advertised by an incoming `Create` or `Announce` are
  fetched after the post is stored, but only through the bounded HTTP client
  and a host resolver that rejects private, loopback, link-local, CGNAT,
  documentation and mapped addresses (SSRF), with manual redirect rechecks,
  a per-hop timeout and a hard body limit. Up to four image attachments are
  parsed from `url`/`mediaType`/`name`, validated by the existing
  `MediaService` (declared vs. sniffed type, dimensions, thumbnail) and stored
  locally. The unique `source_url` makes repeated URLs reuse the existing
  copy. Failed attachments are logged with stable reasons and skipped; the
  post remains available. Remote timeline entries resolve their links and
  present local original/thumbnail URLs plus alt text. The switch
  `social/media.remote_fetch` disables all outbound fetching without deleting
  existing copies.
- Historical remote media catch-up: the outbox backfill passes every newly
  stored and every already-present `Create`/`Announce` object to the same
  `RemoteMediaService` the inbox uses. A later run therefore repairs posts
  whose media was disabled or failed earlier, without a second post row;
  repeated source URLs reuse the existing local attachment. The bounded
  fetcher, SSRF checks, limits, validation, stable failure reasons and the
  `social/media.remote_fetch` switch apply unchanged. Existing links survive a
  repeat in which one candidate can no longer be fetched. This closes the
  local media catch-up gap that was previously left to each host; scheduling
  the backfill remains a host operation.
- Per-user blocks: `Block`/`BlockRepository` (in-memory and PDO, one contract
  test) over `social_block`, an authenticated block API and a home-timeline
  filter scoped to the reader. Blocks hide shared remote rows without deleting
  them or affecting another local account.
- Thread/context fetching: `GET /api/v1/remote-posts/{id}/thread` returns a
  bounded view of a stored remote post: the target object, its ancestors from
  root to parent and its direct replies. The remote activity id is
  URL-encoded as the path segment. The reader must follow the target actor and
  must not block it; ancestors and replies from blocked actors are omitted.
  `RemoteThreadRepository` (in-memory and PDO, one contract test) over
  `social_remote_thread_post` caches fetched nodes by object id, so repeated
  requests and shared ancestors do not duplicate rows. `RemoteFetcher` is the
  shared network guard for media and threads: http(s)-only, public-address
  checks on every hop, manual redirect revalidation, timeout and body limit.
  `social/thread.fetch` switches the route off with a `503`, and
  `thread.max_depth`, `thread.max_replies`, `thread.max_bytes`,
  `thread.timeout_seconds` and `thread.max_redirects` bound work. Ancestor and
  reply failures are logged and skipped; a target failure answers `502`. No
  media is imported into the cache. Expiring old nodes remains a host job via
  `RemoteThreadRepository::deleteFetchedBefore()`.
- Discovery: the ActivityStreams actor document with its published key, and
  the followers `OrderedCollection` read from the follower repository.
- Outbox: the public `OrderedCollection` of an account's public posts as
  `Create` activities, read from the same post repository the local API uses.
- Following: the public `OrderedCollection` of the actors a local account
  follows, read from a separate outgoing follow repository.
- Paged actor collections: followers, following and outbox advertise a
  `first` page and serve `OrderedCollectionPage` documents with `partOf`,
  `next`, `prev` and a bounded page size, backed by repository page queries.
- Local following: `POST`/`DELETE /api/v1/following` writes the edge, delivers
  a signed `Follow`/`Undo`, rolls back a refused Follow and keeps a refused
  Undo retryable.
- WebFinger: `GET /.well-known/webfinger` resolves a local `acct:` handle to
  the actor document URL, with the configured host enforced.
- Local Mastodon notifications: `GET {mastodon.prefix}/notifications` lists
  unread favourite and follow events for the signed-in local account. Local
  favourites, local follows and verified inbound remote Follows record a
  notification; a deterministic event id makes every repeat idempotent. The
  listing supports `max_id`/`since_id`/`min_id`, `types[]`/`exclude_types[]`
  and the `read:notifications` scope, and projects local actors or minimal
  remote actor URLs without a second account model.
- Local Mastodon notification read state: `POST
  {mastodon.prefix}/notifications/{id}/dismiss` and `POST
  {mastodon.prefix}/notifications/clear` set the nullable `read_at` marker
  instead of deleting the historical row. `GET
  {mastodon.prefix}/notifications/unread_count` returns the capped unread
  count. The list omits read rows and exposes `unread` on each remaining
  event. Dismiss and clear are idempotent for the caller; a foreign or missing
  id stays `404 not_found`. The write routes require `write:notifications`,
  the count requires `read:notifications`, and existing hosts add the column
  and unread index through the documented migration. No new table or job is
  required; read rows can be pruned by the host's existing retention policy.
- Local Mastodon instance metadata: `GET {mastodon.prefix}/instance` and the
  core `GET /api/v1/instance` now expose configurable description, version,
  contact email/account, registration/approval/invite flags, languages,
  thumbnail and real local statistics. The metadata comes from the existing
  `social/instance.*` configuration; `user_count`/`status_count`/`domain_count`
  come from optional count capabilities on the local directory and the
  local/remote post repositories, so a custom adapter that cannot cheaply
  count reports zero instead of forcing a scan. The contact account is hidden
  when a supplied directory does not know it. No new table, migration or
  cleanup job is needed. Tests cover configured values, defaults, stale
  contacts, in-memory and SQL counts, and both HTTP projections.
- HTTP: one route manifest, generic kernel, one error shape, JSON export.
- Security: AES-256-GCM secret box.
- Documentation and CI across PHP 8.3, 8.4, 8.5.
- Migration and ownership contract: [ADR 0004](adr/0004-incremental-migration-and-ownership.md),
  the [Nextcloud migration guide](guides/migration-from-nextcloud.md), the
  read-only cutover validator (`bin/validate-cutover`) and the shared UI
  contract in [ADR 0005](adr/0005-shared-ui.md).
- Shared UI package: `@alohasocial/ui` now exists under `ui/` with the typed
  `HostBridge`, Vue dependency-injection plugin, typed `CoreApi`, a network-free
  `FakeHostBridge`, `PostCard` and the first read-only `PublicTimeline` screen.
  The package covers loading, empty, error, retry and cursor pagination states,
  has its own typecheck/test/build commands and a route-parity test against
  `bin/export-routes`. Host bridges, mount pages and Nextcloud/standalone
  navigation remain host work.

## Partially done

- **Mastodon-compatible API**: the core now owns the additive,
  disabled-by-default compatibility subset under `social/mastodon.prefix`
  (default `/api/mastodon/v1`): instance metadata and statistics, public timeline, status
  read/create/delete including local poll creation and voting on an own or
  visible local status, local favourite/unfavourite, local follow/unfollow and relationship reads, local
  notification listing with dismiss/unread state, media upload/update/delete,
  local account statuses and verify credentials. It maps
  to the existing posts, `PostService`, `MediaService` and repositories rather
  than a second status or media store, carries auth and scope metadata
  (`write:statuses`, `write:favourites`, `write:follows`, `write:media`,
  `write:notifications`, `read:accounts`, `read:follows`,
  `read:notifications`, `read:search`),
  implements `max_id`/`since_id`/`min_id` pagination with an id tie-break and
  `Link` headers, offset pagination for search, and makes `Idempotency-Key`
  idempotent through a deterministic post id or media source key. Local favourites live in `social_favourite` behind
  `FavouriteRepository`; counts and the viewer flag are projected without a
  second status model, and unfavourite is an idempotent no-op when the row is
  already absent. Polls live in `social_poll` and `social_poll_option` behind
  `PollSchema` and are attached to the existing post; creation is atomic with
  the post insert. Local votes live in `social_poll_vote` behind
  `PollVoteRepository`; an insert updates the poll and option counters in the
  same transaction, and the status projection returns the viewer's `voted`,
  `own_votes` and the shared counts. An identical retry is idempotent, a
  changed second vote and a vote after expiry are rejected, and `expired` is
  derived from the clock rather than a scheduled job. Local follows use the existing follower graph with
  `social_follower.is_local = 1`, so the target's followers collection and
  both relationship directions share one source of truth; those edges are
  excluded from remote inbox resolution and feed the authenticated home
  timeline. Notifications live in `social_notification` behind
  `NotificationRepository`: a local favourite, local follow or verified remote
  Follow records one row, a deterministic id makes repeats no-ops, and a
  remote follower is projected as a minimal actor URL without fetching its
  profile. Dismiss and clear set `read_at`; the list hides read rows and the
  unread count projects the remaining ones, with no second table. Local search
  uses two optional read ports over the existing stores:
  `PostSearchRepository` matches live public/unlisted `social_post.content`
  (case-insensitive, offset-paged) and `LocalActorSearch` matches the host's
  local account directory. `GET /search` returns the Mastodon v2 object with
  `accounts`, `statuses` and an always-empty `hashtags`, requires
  `read:search`, and supports `type`, `limit`, `offset` and the local
  `following` filter. No search table or index migration is needed; hosts
  without a search implementation answer `503 search_not_configured` for the
  affected type. Instance metadata is projected from the optional
  `social/instance.*` keys; the stats use the optional `LocalActorCount`,
  `PostCount` and `RemoteDomainCount` capabilities over the existing stores,
  with no new table or migration. The prefix is configurable and cannot overlap `/api/v1`; the
  whole surface answers `404 mastodon_api_disabled` when `social/mastodon.api`
  is off. See [Mastodon-compatible API](guides/mastodon-api.md). The full Mastodon
  server API is **not** done. Only these parts are genuinely external:
  OAuth/authorization/token issuance and app registration; real remote
  timelines and remote delivery; favourite federation (outbound `Like`/`Undo`
  and remote counts); boosts; remote notifications; streaming infrastructure;
  remote poll fetching/voting/federation; remote relationship/admin APIs; and
  migrations in an existing host database.
  Local account profiles and paged follower/following lists are complete
  locally too, not host prerequisites. Media upload/update/delete, local
  favourite bookkeeping, local follow/unfollow/relationship reads, local
  notification listing with dismiss/unread state, local status/actor search,
  local poll creation/voting, account profiles/lists and instance
  metadata/statistics are complete locally. The
  migration for the local follow marker remains a host step: add
  `social_follower.is_local SMALLINT NOT NULL DEFAULT 0`; add
  `social_notification` from `NotificationSchema` before exposing the
  notification routes, or add `read_at` and the unread index to an existing
  table as documented; add `social_poll`/`social_poll_option` from
  `PollSchema` before accepting a `poll` parameter. The remaining parts are
  tracked in the Next table, not as a completed item.
- **AT Protocol stack**: the core now owns the public text-only publishing
  foundation — ATProto identity/record primitives, deterministic TIDs, the
  authenticated XRPC `com.atproto.repo.putRecord` adapter, the
  `AtprotoAccountResolver`/`AtprotoPublisher` ports, the idempotent publication
  table and the disabled-by-default `POST /api/v1/posts/{id}/atproto` path.
  Only public text posts are mirrored; media, edits, replies and long posts are
  refused. A local post deletion now queues an idempotent
  `com.atproto.repo.deleteRecord` for a record the core published and removes
  the local publication row only after the PDS accepted it; a 404 is success,
  and a failed delete stays retryable. OAuth session refresh is also local:
  `AtprotoSessionService`, `XrpcAtprotoSessionRefresher` and the ES256 DPoP
  proof adapter rotate a host-owned session at the authorization server, with
  one nonce retry and no core credential table. The authorization-code/PAR flow
  and refresh-token revocation are local too: `AtprotoOAuthMetadataResolver`
  validates the PDS protected-resource and authorization-server documents,
  `XrpcAtprotoOAuthClient` sends the PAR request with PKCE S256 and `dpop_jkt`,
  exchanges the code after checking `iss` and the token `sub`, and revokes the
  refresh token; `DpopKeyThumbprint` is the optional proof-provider capability
  needed for PAR. The host still owns the browser redirect, the short-lived
  state/verifier flow store, credential storage and PDS selection. See
  [AT Protocol publishing](guides/atproto.md). The full stack is **not** done:
  account-linking UI/callback, a full repository/AppView client, CAR/MST, blob
  uploads, firehose and the extraction from the Nextcloud app remain external.
  Remote delete for core-published records, OAuth session refresh,
  authorization-code/PAR and revocation are done locally. The remaining parts
  are tracked in the Next table below, not as a completed item.

## Next

The remaining items are host-owned migrations/integrations, the genuinely
external parts of the two partially done stacks, and explicitly listed
core-side follow-ups. There is no unlisted local vertical slice hiding in this
table: the local media catch-up, thread/context, AT Protocol publishing and
Mastodon compatibility (including instance metadata/statistics, media
management, local favourites, local follow/relationships, local account
profiles and follower/following lists, notification listing with
dismiss/unread state, local search and poll creation/voting) slices are recorded
above.

| Item | Seam |
| --- | --- |
| Full Mastodon server API — external remainder (OAuth/authorization/token issuance and app registration, remote timelines and delivery, favourite federation, boosts, remote notifications, streaming infrastructure, remote poll fetching/voting/federation, remote relationship/admin APIs) | `AlohaSocial/social`/standalone host and future compatibility adapters over the existing Mastodon subset; local media management, favourites, follow/unfollow, relationships, notification listing with dismiss/unread state, account profiles and local follower/following lists, instance metadata/statistics, local status/actor search and poll creation/voting are done, and the `social_follower.is_local`, `social_notification` read-marker plus `social_poll`/`social_poll_vote` migrations remain host steps |
| AT Protocol account-linking UI/callback and full client/AppView extraction (authorization-code/PAR/revocation and OAuth session refresh are local) | `AlohaSocial/social` host and a future standalone AT Protocol client; the host owns credential storage, PDS selection and the browser flow store, while the core owns the protocol client and metadata validation |
| Nextcloud posts/follows import and actor-URL parity | `AlohaSocial/social` host migration, using the core schemas and `bin/validate-cutover` |
| Cutover of posts/timelines, actor surface, follow delivery and inbox | one host feature flag at a time, after the identity gate |
| Nextcloud `HostBridge` integration and rollout of the shared screens | `AlohaSocial/social` host; the package, contract, FakeBridge and first read-only screen are done under `ui/` |
| Migration runner for the standalone | host concern, schemas already exist |

## Later

- Standalone `HostBridge` once `AlohaSocial/server` has its auth flow.

## Explicitly not planned

- A rewrite in another language.
- A "fake Nextcloud" shim as a permanent architecture (see
  [ADR 0001](adr/0001-shared-core-two-hosts.md)).
- Storing configuration or keys in this repository.
