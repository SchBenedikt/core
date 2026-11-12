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
- HTTP: one route manifest, generic kernel, one error shape, JSON export.
- Security: AES-256-GCM secret box.
- Documentation and CI across PHP 8.3, 8.4, 8.5.
- Migration and ownership contract: [ADR 0004](adr/0004-incremental-migration-and-ownership.md),
  the [Nextcloud migration guide](guides/migration-from-nextcloud.md), the
  read-only cutover validator (`bin/validate-cutover`) and the shared UI
  contract in [ADR 0005](adr/0005-shared-ui.md).

## Partially done

- **Mastodon-compatible API**: the core now owns the additive,
  disabled-by-default compatibility subset under `social/mastodon.prefix`
  (default `/api/mastodon/v1`): instance description, public timeline, status
  read/create/delete, local account statuses and verify credentials. It maps
  to the existing posts and `PostService` rather than a second status table,
  carries auth and scope metadata (`write:statuses`, `read:accounts`),
  implements `max_id`/`since_id`/`min_id` pagination with an id tie-break and
  `Link` headers, and makes `Idempotency-Key` idempotent through a
  deterministic post id. The prefix is configurable and cannot overlap
  `/api/v1`; the whole surface answers `404 mastodon_api_disabled` when
  `social/mastodon.api` is off. See
  [Mastodon-compatible API](guides/mastodon-api.md). The full Mastodon server
  API is **not** done: OAuth/token issuance, remote timelines, favourites,
  boosts, notifications, streaming, media upload, polls, search, and the full
  account/admin surface remain external. They are listed in the Next table,
  not as a completed item.
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
  one nonce retry and no core credential table. See
  [AT Protocol publishing](guides/atproto.md). The full stack is **not** done:
  account linking, the authorization-code/PAR flow and token revocation, a full
  repository/AppView client, CAR/MST, blob uploads, firehose and the extraction
  from the Nextcloud app remain external. Remote delete for core-published
  records and OAuth session refresh are done locally. The remaining parts are
  tracked in the Next table below, not as a completed item.

## Next

The remaining items are host-owned migrations, integrations or the external
parts of the two partially done stacks. There is no core-only vertical slice
waiting in this table; the local media catch-up, thread/context, AT Protocol
publishing and Mastodon compatibility slices are recorded above.

| Item | Seam |
| --- | --- |
| Full Mastodon server API (OAuth/token issuance, remote timelines, favourites, boosts, notifications, streaming, media upload, polls, search, account/admin and relationship APIs) | `AlohaSocial/social`/standalone host and future compatibility adapters over the existing Mastodon subset |
| AT Protocol account linking, authorization-code/PAR/revocation and full client/AppView extraction (OAuth session refresh is local) | `AlohaSocial/social` host and a future standalone AT Protocol client, using the new ports |
| Nextcloud posts/follows import and actor-URL parity | `AlohaSocial/social` host migration, using the core schemas and `bin/validate-cutover` |
| Cutover of posts/timelines, actor surface, follow delivery and inbox | one host feature flag at a time, after the identity gate |
| `@alohasocial/ui` extraction and the Nextcloud `HostBridge` | `ui/`, then `AlohaSocial/social` |
| Migration runner for the standalone | host concern, schemas already exist |

## Later

- Standalone `HostBridge` once `AlohaSocial/server` has its auth flow.

## Explicitly not planned

- A rewrite in another language.
- A "fake Nextcloud" shim as a permanent architecture (see
  [ADR 0001](adr/0001-shared-core-two-hosts.md)).
- Storing configuration or keys in this repository.
