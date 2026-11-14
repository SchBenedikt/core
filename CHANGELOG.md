# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- ActivityPub `Move` and identity portability. `AccountMoveService` verifies
  that the signed origin and target actor documents name each other in
  `alsoKnownAs`, rejects a moved target, and re-points outgoing follows and
  accepted incoming followers in resumable batches stored in the new
  `social_account_move` table. Pending old follower requests are dropped rather
  than auto-accepted; actor/key ids and post URLs stay unchanged. The local
  actor document advertises `movedTo`/`alsoKnownAs`, and `HttpActorResolver`
  implements the new `RemoteActorFetcher` port.
- The AT Protocol profile record and an AppView timeline read.
  `AtprotoProfileService` writes `app.bsky.actor.profile` at record key `self`
  (display name, description, avatar/banner through the blob uploader, JPEG/PNG
  up to 1 MB, checked before the first upload) and removes it again.
  `AtprotoTimelineReader`/`XrpcAtprotoTimelineReader` read
  `app.bsky.feed.getTimeline` over the same bearer/DPoP transport and return
  `AtprotoTimelineItem` values plus a cursor; a refusal raises
  `timeline_unavailable`, an unreadable body `timeline_invalid_response`.
- The standalone server blueprint (`docs/guides/server-architecture.md`):
  process topology, module layout, boot order, configuration, migrations, the
  OAuth linking flow, worker, security checklist and the phase plan with
  acceptance checks.
- Likes and reposts as AT Protocol records. `AtprotoInteractionService` writes
  `app.bsky.feed.like` and `app.bsky.feed.repost` for a post this instance
  published and removes them again: the subject is a `strongRef` (URI from the
  publication, CID read with `getRecord`), and the record key is derived from
  the subject post and the account, so a repeated interaction updates the same
  record and an undo needs no local interaction table. `AtprotoPublisher` and
  `AtprotoDeleter` now take the collection (default `app.bsky.feed.post`),
  which is a port signature change. A post without a publication is refused
  with `interaction_subject_not_published`, an unreadable subject with
  `interaction_subject_unresolvable`.
- Image and video embeds. `XrpcAtprotoBlobUploader` uploads raw bytes through
  `com.atproto.repo.uploadBlob` with the media type as Content-Type over the
  shared bearer/DPoP request layer (`AtprotoPdsRequest`, also used by the record
  writer), and reads the blob reference from the answer. `AtprotoEmbed`
  validates before uploading and builds `app.bsky.embed.images` (1–4 images,
  alt text required but allowed to be empty, optional aspect ratio) or
  `app.bsky.embed.video` (one video, mp4, optional alt/aspect ratio). Refusals
  are `blob_mime_unsupported`, `blob_too_large`, `blob_missing`,
  `blob_upload_failed`, `embed_mixed_media`, `embed_too_many_images`,
  `embed_too_many_videos`, `embed_alt_missing`, `embed_alt_too_long` and
  `embed_aspect_ratio_invalid`; `AtprotoPublishService::publish()` accepts the
  media list and writes no record when one of them fails.
- Complete AT Protocol post records. `AtprotoFacets` derives `#tag`, `#mention`
  and `#link` facets with UTF-8 **byte** offsets, drops overlapping candidates
  and resolves mention handles to DIDs through
  `com.atproto.identity.resolveHandle` (`AtprotoIdentityResolver`); an
  unresolvable handle refuses the post instead of writing a mention without a
  DID. `AtprotoReplyBuilder` builds `reply.root`/`reply.parent` from a parent AT
  URI, inherits the thread root of a parent that is itself a reply and looks up
  a missing CID with `com.atproto.repo.getRecord` (`AtprotoRecordResolver`).
  `AtprotoPostRecord::fromText()` assembles the record from text, an optional
  reply reference and facets. Publishing a reply now needs a parent that was
  itself published from this instance; the new refusal reasons are
  `reply_parent_not_published`, `replies_unsupported`, `reply_parent_unresolvable`,
  `reply_root_unresolvable` and `mention_unresolved`.
- DPoP-bound PDS requests. `XrpcAtprotoPublisher` accepts an optional
  `DpopResourceProofProvider` (`Es256DpopProofProvider` implements it with the
  RFC 9449 `ath` binding) and then sends `Authorization: DPoP` with a fresh
  proof, retrying exactly one `use_dpop_nonce` challenge; without a provider it
  keeps the bearer token. `Application::inMemory()` and `forHost()` take the
  optional `atprotoDpop` port and pass it to the default publisher.
- Guard the configuration reference with code. `DocumentationTest` resolves
every `Config` key the core reads — declared constants and literals alike —
and fails when `docs/reference/configuration.md` does not name it.
- Complete the outgoing ActivityPub follow lifecycle: a Follow is stored as
  `pending`, delivered by a durable `federation.follow` queue job with retries,
  and moved to `accepted` or `rejected` only by a verified Accept/Reject from
  the target actor. Unfollow queues the Undo and removes the row, so duplicate,
  late and competing responses are safe no-ops. The public collection and home
  timeline read accepted rows only.
- Accept inbound `Undo(Announce)` in both its embedded and id-only forms,
  removing only a boost owned by the signing actor and treating an absent
  target or a retry as an idempotent no-op.
- Guard outbound federation targets against SSRF in delivery, actor-document
  resolution and inbox discovery, with public-DNS checks, connection pinning
  on a bounded client and a guard around host-provided actor resolvers.
- Prepare the Composer and npm release artifacts without claiming a registry
  publication: `.gitattributes` and `composer.json` `archive.exclude` keep
  `vendor/`, `tests/`, `ui/`, `docs/` and development files out of the library
  archive; the UI tarball is built from `dist/`, ships `LICENSE` and declares
  public scoped-package access. The release checklist and the missing
  credentials are in `docs/operations/release.md`.
- Enforce the core/UI release pair and HostBridge contract with code rather
  than documentation. `Version::CURRENT` and `ui/package.json` are the package
  authorities; PHPUnit checks the UI lockfile and TypeScript constants, while
  the UI suite checks the package pair and accepts or rejects bridge contracts
  and capabilities in both directions. The HostBridge field is optional and an
  omitted value means contract 1, so existing bridges keep working. Test counts
  are now 124 PHP files (1111 tests, 5302 assertions) and 9 UI files (69 tests).

### Changed

- Finalize [ADR 0006](docs/adr/0006-host-owned-accounts.md) as accepted: the
  host owns accounts, sessions, credentials, roles and instance-administrator
  rights; the core receives only `Request::$userId` and host-resolved scopes.
  The ADR now records the `HostBridge`/`UiUser` boundary, the read-only
  `LocalActorDirectory` role, the policy ports and the host rollout. No core
  table, port, route or code behaviour changed.

## [0.7.0] - 2026-11-13

### Added

- `Platform\Db\TransactionRunner`, a narrow port for a use case that writes
  through several repositories. `PdoConnection` implements it, and
  `Application::forHost()` detects a database that implements it automatically;
  a host can also pass a dedicated `transactions` adapter. Nested calls join
  the outer transaction, and an exception at any depth rolls the whole chain
  back.
- `PostService::create()` and `delete()` now run the post row, attachment /
  hashtag / poll / recipient side rows, the AT Protocol publication job and
  every federation delivery job in one transaction when a runner is available.
  A rejected link, AT Protocol write or queue push can no longer leave a
  visible post without its side rows, or a soft-deleted post with a successful
  delivery job. Validation still happens before the transaction, so invalid
  input writes nothing at all. Mentions remain deliberately best-effort at the
  notification boundary: a successful notification insert joins the
  transaction, while a storage failure is logged and does not undo the post.
- `JobQueue::deadLetter()`, implemented by `InMemoryJobQueue` and
  `PdoJobQueue`. Terminal job errors are now recorded immediately instead of
  consuming the remaining retry budget. `PdoJobQueue::complete()` and
  `deadLetter()` only act on a currently reserved job, so a stale or duplicated
  worker cannot delete a pending row or bury an unreserved retry.
- `JobFailureCode` and the `TerminalJobException` contract. The stable worker
  codes are `invalid_job_type`, `invalid_job_payload`, `unknown_job_type`,
  `job_handler_failed` and `job_completion_failed`; they are log/operator
  codes, not HTTP status codes. Empty job types are rejected at push time with
  `InvalidJobException::errorCode = invalid_job_type`.
- `bin/core-demo` now boots a real `Application::forHost()` over in-memory
  SQLite, commits one post with its hashtag and queued delivery job, then sends
  a second post through a rejecting queue. It prints the `500` response and
  asserts that the post and hashtag counts did not change. This exercises the
  real transaction and needs `ext-pdo_sqlite`, but no external database or
  network.

### Changed

- `JobWorker` separates handler failures from completion failures. A terminal
  payload/type error is dead-lettered at once; a generic handler failure is
  retried with the normal backoff; when the handler succeeded but removing the
  reservation failed, the worker logs `job_completion_failed` and does **not**
  call `fail()`, because retrying would repeat an already completed side
  effect. The durable queue's reservation TTL recovers the row.
- Adding `deadLetter()` to `JobQueue` is a breaking change for a custom host
  adapter. The built-in queues and all test doubles implement it; a host must
  add the method before upgrading.
- The suite is now 120 PHP test files with 1077 tests and 5103 assertions; the
  UI package stays at 63 tests. No HTTP route, schema or OpenAPI path was
  added: the advertised contract stays at 84 routes and 70 OpenAPI paths.

## [0.6.0] - 2026-11-13

### Added

- Core retention cleanup: the `cleanup` worker job and `CleanupService`
  remove unreferenced media attachments older than 7 days, **read**
  notifications older than 30 days and `social_remote_thread_post` cache rows
  fetched more than 7 days ago by default. The payload accepts `kind`
  (`media`, `notifications`, `threads` or `all`), three retention values
  (1–3650 days), a per-kind `limit` (1–1000) and `dry_run`; unknown keys and
  wrong types are rejected with the stable reasons `cleanup_invalid_kind`,
  `cleanup_invalid_retention`, `cleanup_invalid_limit`,
  `cleanup_invalid_dry_run` and `cleanup_unknown_parameter`. Runs are bounded
  oldest-first, report `matched`/`deleted`/`remaining` per kind, are idempotent
  and recheck the post link at delete time. Unread notifications, linked media
  (including media used by soft-deleted posts) and fresh rows are never
  removed. A missing `BlobStore` skips media with
  `cleanup_media_unavailable`; a failed blob delete logs
  `cleanup_blob_delete_failed` after the metadata row is committed. Invalid
  job payloads are logged and the job is completed instead of retried
  forever; database/storage failures propagate to the normal queue backoff.
- Contract tests for `AttachmentRepository::unattachedBefore()`,
  `countUnattachedBefore()`, `deleteIfUnattached()`,
  `NotificationRepository::countReadBefore()`/`deleteReadBefore()` and
  `RemoteThreadRepository::countFetchedBefore()`/`deleteFetchedBefore()`, plus
  service/options tests and a SQL host test that runs the job through
  `Application::forHost()`. `RemotePostRepository` gains
  `isAttachmentLinked()` so the in-memory adapter applies the same local and
  remote link rule as SQL; the core implementations and test doubles are
  updated.
- `bin/core-demo` now seeds expired and fresh media, linked media, read and
  unread notifications and two thread-cache nodes, prints the dry-run report,
  runs the real job and asserts the exact remaining rows and blobs.
- Documentation: a [retention cleanup guide](docs/guides/retention-cleanup.md),
  the job payload and reasons in the route/error references, database and host
  wiring notes, migration guidance, README and roadmap entries. The feature
  adds no HTTP route, so the advertised contract stays at 84 routes and 70
  OpenAPI paths.
- The suite is now 118 PHP test files with 1060 tests and 5048 assertions;
  the UI package stays at 63 tests.

## [0.5.0] - 2026-11-13

### Added

- Private followed hashtags: `GET`, `POST /api/v1/followed_tags` and
  `DELETE /api/v1/followed_tags/{tag}` list, follow and unfollow normalized
  tags for the signed-in account. Follows are idempotent, scoped to the
  caller and kept in `social_followed_hashtag`; a duplicate follow keeps its
  original timestamp, an absent unfollow is a no-op, malformed input is `400
  invalid_tag`, and the bounded 100-tag limit answers `400
  followed_tag_limit`. `GET /api/v1/timelines/followed_tags` returns the
  public/unlisted posts carrying any followed tag, newest first, with the same
  limit and exclusive `before` cursor as the other timelines. A post with two
  followed tags appears once, and only exact matches count. The tag page uses
  the exclusive `before`/`before_tag` pair. Tests cover both repositories, the
  API, SQL host wiring and the cursor/visibility rules; `core-demo` follows
  `#demo`, reads the timeline and unfollows it again.

## [0.4.0] - 2026-11-13

### Added

- Public hashtag timelines: `GET /api/v1/timelines/tag/{tag}` returns local
  public and unlisted posts carrying an exact, normalized hashtag. Tags are
  extracted from post content on insert, stored in `social_post_hashtag` and
  matched case-insensitively without prefix matches; followers-only, direct
  and deleted posts never appear. The route uses the existing limit and
  `before` cursor, accepts an optional leading `#`, and rejects an empty or
  malformed tag with `400 invalid_tag`. `PublicTimeline` accepts an optional
  `tag` and renders the same screen against the new route; the UI has no
  separate hashtag component.

## [0.3.0] - 2026-11-13

### Added

- Neutral local interaction API under `/api/v1`: `GET
  /api/v1/accounts/{id}` returns a public local profile with the viewer's
  relationship, `POST`/`DELETE /api/v1/accounts/{id}/follow` write the local
  follow edge idempotently, and `GET /api/v1/notifications`,
  `GET /api/v1/notifications/unread_count`, `POST
  /api/v1/notifications/{id}/dismiss` and `POST
  /api/v1/notifications/clear` manage the caller's unread events. The new
  `LocalAccountService` reuses the existing follow graph and notification
  table; there is no second account or event model, and the routes do not
  require the Mastodon-compatible prefix. Profiles and follows answer `404
  not_found` for unknown local ids, `422 validation_failed` for a self-follow,
  `401` without a user and `503 accounts_not_configured` without a local
  directory. Notification cursors are caller-owned ids; an unknown or foreign
  cursor is `422`, a foreign dismiss is `404`, and an unavailable table is
  `503 notifications_unavailable`. The manifest and OpenAPI export now have 79
  routes (43 under the compatibility prefix). Tests cover profiles, viewer
  relationships, idempotent follow/unfollow, notification recording from a
  follow and a reply, paging, dismiss/clear, ownership and storage failure.
- Shared write screens: `@alohasocial/ui` now exports `ActorHeader` (profile
  and follow toggle), `PostComposer` (content, visibility, content warning,
  core validation errors) and `NotificationList` (unread list, dismiss, clear,
  cursor paging, signed-out/401 state). `CoreApi` gains typed account/follow,
  notification and create-post methods plus client-side shape checks;
  `FakeHostBridge` component tests cover the loading, error, write and
  authentication states. The screens consume the neutral `/api/v1` routes, so
  the host bridge contract is unchanged. Host bridges and mount pages remain
  in `AlohaSocial/social` and `AlohaSocial/server`.

### Changed

- The shared UI rollout is split into M5a (neutral interaction API and write
  screens, now done) and M5 (mounting the screens in both hosts, still open).
  `docs/reference/routes.md`, the README, architecture, shared-UI guide, ADR
  0005 and AGENTS.md describe the new routes and screens; the advertised
  counts are 79 routes, 1029 PHP tests and 59 UI tests.

## [0.2.0] - 2026-11-13

### Changed

- Documentation roadmap: `docs/roadmap.md` is now the canonical
  goal/status document with milestones M0–M7, owners and exit criteria. The
  README, AGENTS.md, architecture, standalone-host checklist, shared-UI
  rollout, Mastodon/AT Protocol remainder, migration guide and FAQ were aligned
  with it. Stale FAQ answers (direct posts, block federation), the incomplete
  standalone schema/repository/service list and six missing Mastodon rows in
  the route reference were corrected.
- Documentation: `README.md` now states the complete locally implemented
  surface, separates core features from host/external responsibilities and
  links the current contracts and guides. The follow-graph, timeline and block
  guides now describe the existing local-follow home timeline and local actor
  blocks; a new end-to-end test proves that blocking a followed local account
  hides its local posts from the home timeline until it is unblocked.

### Fixed

- A followers-only local post is now readable through `GET
  /api/v1/posts/{id}` and the Mastodon status route by an accepted local
  follower, not only by its author. This makes the detail route agree with the
  local-follow home timeline; unfollowing revokes the read again. Direct posts
  remain private: they appear only for their author and local recipients.
- `GET /api/mastodon/v1/accounts/{id}/statuses` now applies the same
  followers-only rule and viewer flags as the status detail route: the author
  and accepted local followers see the rows, other viewers do not, and an
  authenticated viewer gets their own favourite/poll state.

### Added

- Dual-protocol documentation: the README now has Mermaid diagrams for the
  local-model fan-out and the release timeline, and
  `docs/guides/dual-protocol.md` plus ADR 0007 explain that ActivityPub and AT
  Protocol stay two wire protocols over one local model rather than becoming a
  third protocol.
- AT Protocol publication state read: `GET /api/v1/posts/{id}/atproto` returns
  `{"publication": null}` or the stored `post_id`/`rkey`/`uri`/`published_at`
  row for the caller's own post. It reads the local idempotency record, not the
  PDS, so it remains available when publishing is disabled; a missing,
  deleted or foreign post is `404`, an anonymous request is `401`. The route
  requires authentication and is rate-limited at `120/60`. See
  `docs/guides/atproto.md`.
- ADR 0006 (proposed at this point, since [accepted](docs/adr/0006-host-owned-accounts.md)):
  accounts, sessions, credentials and token issuance stay host-owned; the core
  keeps the `Request::$userId`/`scope` seam and adds no user table or identity
  port. This closes the M4 prerequisite in the roadmap.
- Documentation consistency test: `tests/Contract/DocumentationTest.php`
  compares `docs/reference/routes.md` with `Routes::manifest()`, checks the
  advertised route counts in the route reference, roadmap and README, and
  verifies that every `*Schema` class is listed in the database guide.
- Private Mastodon-compatible lists: `POST/GET {mastodon.prefix}/lists`
  create and list the caller's private lists, and `GET`/`PUT`/`DELETE
  {mastodon.prefix}/lists/{id}` read, update and delete one. A list carries a
  trimmed title, a `replies_policy` of `followed`, `list` or `none` and an
  `exclusive` flag. `GET`/`POST`/`DELETE
  {mastodon.prefix}/lists/{id}/accounts` page, add and remove known local
  account ids idempotently, with ownership-checked cursors, a `Link` header and
  at most 100 ids per write. `GET
  {mastodon.prefix}/timelines/list/{list_id}` merges the members' local posts
  and boosts with the normal visibility, block and mute filters, applies the
  replies policy, and uses the usual status cursor. An exclusive list hides
  its members from the local-follow home timeline. The routes require
  `write:lists`/`read:lists`. `AccountList`/`AccountListRepository`,
  `ListRepliesPolicy`, in-memory and PDO adapters, `AccountListSchema`,
  `AccountListService`, HTTP/domain/host/timeline tests and the host migration
  documentation are included.
- Local Mastodon-compatible mutes: `POST
  {mastodon.prefix}/accounts/{id}/mute` stores one private mute per local
  account and local account id or remote actor URL, with an optional
  `notifications` flag and a seconds-based `duration` that expires without a
  cleanup job. `POST .../unmute` removes it and `GET {mastodon.prefix}/mutes`
  lists active mutes newest-first with `max_id`/`since_id`/`min_id`, an
  ownership-checked account cursor and the usual `Link`. Active mutes hide the
  target from the home timeline (including boosted object authors);
  notification mutes also filter the list and unread count. Relationships and
  statuses expose `muting`, `muting_notifications` and `muted`. The routes
  require `write:mutes`/`read:mutes`. `Mute`/`MuteRepository`, in-memory and
  PDO adapters, `MuteSchema`, HTTP/domain/host/timeline/notification tests and
  the host migration documentation are included.
- Private Mastodon-compatible bookmarks: `POST
  {mastodon.prefix}/statuses/{id}/bookmark` and `.../unbookmark` store one
  `social_bookmark` row per local account and visible status, expose the
  viewer-specific `bookmarked` flag without a public count or notification, and
  are idempotent through the composite key. `GET {mastodon.prefix}/bookmarks`
  lists live bookmarks newest-first with `max_id`/`since_id`/`min_id`, an
  ownership-checked status cursor and the usual `Link`; `write:bookmarks` and
  `read:bookmarks` gate the routes. `Bookmark`/`BookmarkRepository`,
  in-memory and PDO adapters, `BookmarkSchema`, HTTP/domain/host tests and the
  host migration documentation are included.
- Direct posts and replies: `CreatePost` and `Post` carry `recipient_ids` and
  `in_reply_to_id`. `PostService` validates known local recipients, enforces
  the parent visibility rules, inherits participants for a direct reply and
  keeps direct posts local (no remote delivery). Direct posts appear in the
  author's and recipients' home timelines and are readable only by them;
  `social_post_recipient` stores the ordered audience in PDO. The HTTP route,
  post projection and `PostSchema` expose both fields. Tests cover direct
  visibility, recipient validation, direct replies, reply widening refusal,
  repository round-trips and home-timeline inclusion.
- Block federation: `BlockService` stores the block first, resolves the remote
  actor's individual inbox through the new `ActorInboxResolver` port and
  queues a signed `Block` or `Undo(Block)` with the existing delivery queue.
  `ActivityFactory` builds both activities with the actor URL as the object.
  A missing or mismatching actor document keeps the local block and queues
  nothing. `BlockRepository::findById()` supports the unblock path.
- Mastodon compatibility for direct statuses and replies: `visibility=direct`
  accepts `recipient_ids` or local `@id` mentions, `in_reply_to_id` is passed
  through, and statuses project `in_reply_to_id`,
  `in_reply_to_account_id` and recipient `mentions`.
- Shared UI post detail: `@alohasocial/ui` now exports `PostDetail` and
  `usePostDetail()`. The second read-only screen consumes the existing core
  `GET /api/v1/posts/{id}` route through `CoreApi.getPost()`, renders the same
  `PostCard`, and covers loading, not-found, error, retry and 401 states. A
  changed post id cancels the previous view logically, so a late response
  cannot overwrite the new detail. The screen has no new core route, table,
  migration or host contract; `FakeHostBridge` component tests exercise the
  existing path. See `ui/README.md` and `docs/guides/shared-ui.md`.
- Mastodon-compatible local poll voting: `POST
  {mastodon.prefix}/polls/{id}/votes` accepts JSON or form `choices[]`
  positions, requires `write:statuses`, declares `30/900` and remains behind
  the disabled-by-default gate. `GET {mastodon.prefix}/polls/{id}` is public
  (`120/60`) and includes viewer-specific `voted`/`own_votes` when the host
  supplies a user id. Votes live in the new `social_poll_vote` table behind
  `PollVoteRepository`; an insert advances the stored poll and option counters
  in one transaction. Single-choice and multiple-choice rules, zero-based
  positions, duplicate/out-of-range choices, expiry, private visibility,
  missing ids, authentication, scope, disabled feature and SQL counters are
  covered through the real kernel. An identical retry is idempotent, a
  changed second vote is `422 already_voted`, and an ended poll is `422
  poll_ended` without a write. The route manifest/OpenAPI export now has 51
  routes (25 under the compatibility prefix). Existing hosts must create the
  vote table from `PollSchema` before enabling local votes; status and poll
  reads degrade to `voted: false` until it exists. Remote poll fetching/voting
  and ActivityPub `Question` federation remain external. See
  `docs/guides/mastodon-api.md`.
- Shared UI package: `@alohasocial/ui` now lives under `ui/` with a typed
  `HostBridge`, `createAlohaUi()`/`useHostBridge()` dependency injection, a typed
  `CoreApi` over the existing route manifest, a network-free `FakeHostBridge`
  and the first read-only screen (`PublicTimeline` plus `PostCard`). The
  timeline uses the existing public-timeline path and `limit`/`before` cursor,
  renders escaped content, content warnings, times and attachment alt text,
  and covers loading, empty, error, retry, 401 and load-more states. The
  package has strict TypeScript, Vitest component/contract tests, an ES-module
  build and a route-parity test against `bin/export-routes`; CI runs it in a
  separate `UI package` job. Host bridges, Nextcloud mount pages, login/logout
  and navigation remain host-owned; there is no new core route, table or
  migration. See `ui/README.md` and `docs/guides/shared-ui.md`.
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
  entity with initially zero local vote counts, `voted: false`, empty
  `own_votes`/`emojis` and a clock-derived `expired` flag. Status reads,
  timelines, account statuses and search project the same object; an
  idempotent retry reuses the original post and does not duplicate poll rows.
  Invalid options, expiry or booleans are `422 validation_failed` and create
  no partial status. The instance endpoint now advertises the real `polls`
  limits. Existing hosts must create the two tables before the repository reads
  polls or a poll is accepted; a fresh host gets them from
  `PollSchema::create()`. Local voting is added separately below; remote poll
  fetching/voting and ActivityPub `Question` federation remain external. See
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
