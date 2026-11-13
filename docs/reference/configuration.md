# Configuration and limits

The core reads configuration through `Platform\Config\Config`, a two-level
store (`app`, `key`). A host maps its own source onto it — `config.php`, `.env`,
the database — the core does not care.

## Keys

| App | Key | Required | Meaning |
| --- | --- | --- | --- |
| `social` | `instance.url` | for federation | Absolute http(s) base URL, no trailing slash required |
| `social` | `instance.name` | no | Display name; defaults to the product name |
| `social` | `instance.description` | no | Public description used by both instance endpoints; defaults to empty |
| `social` | `instance.version` | no | Version segment in the Mastodon compatibility string; defaults to `0.1.0` |
| `social` | `instance.contact_email` | no | Public administrator contact address. `instance.email` remains accepted as a fallback; defaults to empty |
| `social` | `instance.contact_account` | no | Local account id projected as the instance contact account. A supplied directory must contain it or the core field is empty and the Mastodon projection is `null`; defaults to empty |
| `social` | `instance.registrations` | no | Whether registrations are open; defaults to `false` |
| `social` | `instance.approval_required` | no | Whether registrations need approval; defaults to `true` |
| `social` | `instance.invites_enabled` | no | Whether invites are enabled; defaults to `false` |
| `social` | `instance.languages` | no | Comma-separated or JSON array of language codes; defaults to an empty list |
| `social` | `instance.thumbnail` | no | Public thumbnail URL; defaults to empty |
| `social` | `media.max_bytes` | no | Local upload ceiling; defaults to 5 MiB and is clamped to `Attachment::MAX_BYTES` |
| `social` | `media.thumbnail_max_dimension` | no | Longest thumbnail side in pixels; defaults to 640, clamped to 1–2000 |
| `social` | `media.remote_fetch` | no | Enables fetching images advertised by incoming federated posts and historical posts processed by the outbox backfill; defaults to `true`. Set `false`/`0`/`off`/`no` to disable all remote media requests |
| `social` | `media.remote_max_bytes` | no | Hard per-response body limit for a remote image; defaults to 5 MiB, clamped to 1 byte–`Attachment::MAX_BYTES` |
| `social` | `media.remote_timeout_seconds` | no | Per-hop remote fetch timeout; defaults to 10, clamped to 1–30 |
| `social` | `media.remote_max_redirects` | no | Redirects followed per remote fetch; defaults to 3, clamped to 0–5 |
| `social` | `thread.fetch` | no | Enables the authenticated thread/context route. Defaults to `true`; `false`/`0`/`off`/`no` makes the route answer `503 thread_fetch_disabled` without a request to the peer |
| `social` | `thread.max_depth` | no | Maximum ancestors walked from the target; defaults to 5, clamped to 0–10. `0` returns only the target and its direct replies |
| `social` | `thread.max_replies` | no | Maximum direct replies returned; defaults to 20, clamped to 0–40 |
| `social` | `thread.max_bytes` | no | Hard per-response body limit for a thread object or replies collection; defaults to 1 MiB, clamped to 1 byte–5 MiB |
| `social` | `thread.timeout_seconds` | no | Per-hop thread fetch timeout; defaults to 10, clamped to 1–30 |
| `social` | `thread.max_redirects` | no | Redirects followed per thread fetch; defaults to 3, clamped to 0–5 |
| `social` | `atproto.publish` | no | Enables the AT Protocol publication job, the matching remote delete and `POST /api/v1/posts/{id}/atproto`; defaults to `false`. Requires an `AtprotoAccountResolver` and an `AtprotoPublisher`; remote delete also needs an `AtprotoDeleter` (the default XRPC publisher implements both) |
| `social` | `mastodon.api` | no | Enables the Mastodon-compatible routes; defaults to `false`. When off, every route under the prefix answers `404 mastodon_api_disabled` before auth/scope checks |
| `social` | `mastodon.prefix` | no | Path prefix for the compatibility routes; defaults to `/api/mastodon/v1`. Must not be `/api/v1`, a child of it, `/healthz`, `/inbox`, `/@` or `/.well-known`; a bad prefix fails at boot |

Search adds no configuration key. It reads the existing `social_post` rows through
`PostSearchRepository` and the host's local account directory through
`LocalActorSearch`; the default assemblies wire both when the supplied stores
implement them. A type whose capability is absent answers `503
search_not_configured`, and `following=true` also needs the existing
`LocalRelationshipService`. There is no search table or index migration, and
nothing to clean up: search only reads existing rows.

The `instance.*` metadata keys feed the core `GET /api/v1/instance` response and
the Mastodon-compatible `GET {mastodon.prefix}/instance` projection. The counts
in that response come from optional capabilities on the existing stores:
`LocalActorCount` on the account directory, `PostCount` on the local post
repository and `RemoteDomainCount` on the remote-post repository. A store that
omits a capability reports zero for that field; it is not an error and does not
trigger an unbounded scan. The in-memory and PDO post repositories and the
in-memory directory implement the capabilities, and the PDO remote-post
repository derives distinct hosts from live rows. These values are read-only:
there is no instance or statistics table, no migration and no cleanup job. A
stale `instance.contact_account` is hidden when a local directory is supplied
and the id is unknown; without a directory the host remains responsible for
the configured id.

`instance.url` is read when an activity is built and when a media URL is
presented. A post with an audience on an instance without it fails with `503
instance_not_configured` instead of sending documents with a broken id. Media
URLs stay relative when it is unset, which is useful for a local-only host but
not for federation.

With `media.remote_fetch` disabled, an incoming `Create` or `Announce` is
still stored and appears in the home timeline, but no remote request is made
and the post has no `attachments` key. The same applies when an explicit
outbox backfill stores a historical post or revisits an existing one. Existing
imported attachments remain readable; disabling the switch does not delete
rows or blobs. A later backfill with the switch enabled can repair a post from
that period. A host that only supplies `HttpClient` and not
`BoundedHttpClient` also gets no remote fetch: `Application` wires the
importer only when the bounded capability is present.

With `thread.fetch` disabled, `GET /api/v1/remote-posts/{id}/thread` answers
`503 thread_fetch_disabled`; it does not return an empty or stale thread. The
same applies when the assembly has no bounded HTTP client
(`503 thread_not_configured`) or no `RemoteThreadService`. Existing cached
nodes remain stored and readable by a host through
`RemoteThreadRepository`, but the route does not fall back to them without a
fresh target fetch. Disabling the switch does not delete rows. There is no
automatic cleanup timer: a host that wants a TTL calls
`RemoteThreadRepository::deleteFetchedBefore()` on its own schedule.

`thread.max_bytes` applies to each fetched object or replies collection, not
to the whole response. Depth and reply limits are applied after fetching, so a
collection can be read up to the body limit and then truncated to
`thread.max_replies`; the cache never receives the truncated-away nodes.

With `atproto.publish` disabled, post creation and post deletion schedule no
AT Protocol job and the explicit route answers `503 atproto_publish_disabled`
without a PDS request. Existing rows in `social_atproto_publication` remain;
turning the switch back on does not publish old posts automatically and does
not delete remote records that were orphaned while it was off. A host that
wants to recover such a row has the stored `uri`/`rkey` and can delete it with
its own AT Protocol client, then remove the row with
`AtprotoPublicationRepository::removeForPost()`. With the switch enabled but
no resolver/publisher assembled, the route answers `503
atproto_not_configured`; without an `AtprotoDeleter` a post deletion queues no
job while publishing still works. The feature only mirrors public text posts
without attachments; other posts are skipped automatically and refused with
`422 atproto_post_unsupported` when published explicitly.

OAuth session refresh has no `social` key and no route. A host constructs
`AtprotoSessionService` with its `AtprotoSessionStore` and
`XrpcAtprotoSessionRefresher`; the service uses a 60-second expiry leeway.
`AtprotoSessionRefreshException` classifies transport, response and OAuth
errors, and a rotated refresh token is single-use. Tokens remain in the host's
secret store: the core ships only `InMemoryAtprotoSessionStore` for tests and
no session table or migration. See
[AT Protocol publishing](../guides/atproto.md#oauth-session-refresh).

The authorization-code/PAR/revocation client also has no `social` key and no
route. A host that offers account linking calls
`AtprotoOAuthMetadataResolver` and `XrpcAtprotoOAuthClient` directly, stores
the returned authorization request by state between the browser redirect and
the callback, and saves the resulting `AtprotoOAuthSession` in its own
credential store. Disabling publishing does not disable account linking; the
host decides whether to expose it. There is no core flow/session table, so
there is no cleanup job for flow rows: the host expires its own flow store.
See [AT Protocol publishing](../guides/atproto.md#authorization-code-flow).

Shared inboxes have no configuration key. Both inbox routes are controlled by
the same prerequisites as the normal inbox: without an actor resolver, an
HTTP client and a signing key they answer `503 inbox_not_configured`. Outbound
batching uses the peer's advertised `endpoints.sharedInbox`; a follower row
with `shared_inbox_url = NULL` uses its individual inbox. The protocol always
advertises the local shared route in the actor document. See
[shared inboxes](../guides/shared-inboxes.md).

With `mastodon.api` disabled, no Mastodon compatibility handler runs and every
route under `mastodon.prefix` answers `404 mastodon_api_disabled`; the routes
are still in the manifest and OpenAPI export. Enabling it reuses
`social_post`/`social_post_attachment` for statuses and `social_attachment`
plus the existing `BlobStore` for media upload, metadata update and delete;
an `Idempotency-Key` is translated into a deterministic post id or media
source key. Media update changes only the description, and delete is allowed
only while no post link references the attachment; neither adds a table. Poll
creation writes `social_poll` and `social_poll_option` in the same transaction
as the post; create both with `PollSchema::statements()` before serving status
reads through the SQL repository. Local
favourite/unfavourite writes the new `social_favourite` table through
`FavouriteRepository`; create it with `FavouriteSchema::statements()` through
the host migration path before exposing those routes. There is no backfill,
and the table has a composite `(user_id, post_id)` primary key, so a repeat
cannot duplicate a favourite. Local notifications use the new
`social_notification` table through `NotificationRepository`; create it with
`NotificationSchema::statements()` before enabling the notification routes.
Its primary key is a deterministic event id, so a repeated favourite, local
follow or remote Follow cannot create a second row. Notification recording is
best-effort: if the table is missing or the write fails, the primary
favourite/follow write still succeeds, the failure is logged, and a repeat of
the event can heal the missing row. The notification routes themselves still
need the table. A host upgrading from the listing-only slice adds the read
marker and unread index before enabling dismiss, clear or unread-count:

```sql
ALTER TABLE social_notification ADD COLUMN read_at VARCHAR(20) NULL;
CREATE INDEX social_notification_unread
  ON social_notification (recipient_id, read_at, created_at, id);
```

Existing rows are unread (`NULL`) and no backfill is needed. There is no
backfill from older favourites/followers, and deleting a post or follow
deliberately does not delete the historical notification. Notification rows,
including read rows, can be cleaned up by recipient or age in a host job; the
core schedules no cleanup and uses no second read-state table. Local follow/unfollow writes the existing
`social_follower` edge with `is_local = 1`; existing hosts must add the column:

```sql
ALTER TABLE social_follower ADD COLUMN is_local SMALLINT NOT NULL DEFAULT 0;
```

Existing rows default to remote (`0`), so no backfill is needed. The marker
keeps local edges out of `inboxesFor()` delivery resolution and out of signed
Follow/Undo delivery. Relationship reads and both writes need a local account
directory and the assembled `LocalRelationshipService`; without them the
routes answer `503 follows_not_configured`. The local edge disappears with an
unfollow, so there is no separate cleanup job. Media upload needs the same
`MediaService`/`BlobStore` as `/api/v1/media`; without one the route answers
`503 media_not_configured`, and a storage failure after validation answers
`503 media_storage_unavailable`. A manually assembled service without a
`FavouriteRepository` answers `503 favourites_not_configured`; the standard
`Application` assemblies always provide one. A manually assembled service
without a `NotificationRepository` answers `503
notifications_not_configured`; a missing or unreadable notification table
answers `503 notifications_unavailable`. The standard assemblies always
provide the repository. The host must
map bearer tokens to `Request::$userId` and `Request::$scopes`; a parent scope
such as `read` grants `read:accounts`, `read:follows` and
`read:notifications`, `write` grants `write:favourites`, `write:follows`,
`write:media` and `write:notifications`, a granular scope does
not grant its parent, and a token without the route's scope gets `403
insufficient_scope`.
The declared rate limits are enforced by the host like every other route. The
prefix must not overlap `/api/v1`; changing it changes both the served routes
and, when passed explicitly, the exported manifest. Existing data needs no
cleanup or backfill, and disabling the feature deletes nothing. See
[Mastodon-compatible API](../guides/mastodon-api.md).

`Config` is not a secret store. Private keys are passed as objects, and when a
host persists them it seals them with `Security\SecretBox`.

Remote media limits and failure reasons are documented operationally in
[media](../guides/media.md#remote-attachments-from-incoming-posts). The fetch
requires a `BoundedHttpClient` plus a `HostResolver`; the local `CurlHttpClient`
and `DnsHostResolver` provide both. Thread/context uses the same guard and is
documented in [remote threads](../guides/remote-threads.md).

## Limits

| Constant | Value | Meaning |
| --- | --- | --- |
| `PostService::MAX_CONTENT_LENGTH` | 5000 | Characters after trimming; also the compatibility status ceiling |
| `MastodonService::DEFAULT_LIMIT` | 20 | Default page size of the compatibility timelines, notification list and search |
| `MastodonService::MAX_LIMIT` | 40 | Hard page-size ceiling of the compatibility timelines, notification list and search |
| `MastodonService::DEFAULT_ACCOUNT_LIST_LIMIT` | 40 | Default page size of the local Mastodon follower/following lists |
| `MastodonService::MAX_ACCOUNT_LIST_LIMIT` | 80 | Hard page-size ceiling of the local Mastodon follower/following lists |
| `MastodonService::DEFAULT_NOTIFICATION_COUNT_LIMIT` | 100 | Default cap for `GET /notifications/unread_count` |
| `MastodonService::MAX_NOTIFICATION_COUNT_LIMIT` | 1000 | Hard cap for `GET /notifications/unread_count` `limit` |
| `MastodonService::MAX_SEARCH_OFFSET` | 1000 | Hard offset ceiling for the compatibility search |
| `Poll::MIN_OPTIONS` / `Poll::MAX_OPTIONS` | 2 / 4 | Option count accepted by local poll creation and advertised by the instance endpoint |
| `PollOption::MAX_TITLE_LENGTH` | 50 | Characters per poll option after trimming |
| `Poll::MIN_EXPIRY_SECONDS` / `Poll::MAX_EXPIRY_SECONDS` | 300 / 604800 | Expiry window accepted by `expires_in`/`expires_at`; advertised by the instance endpoint |
| `PostService::MAX_CONTENT_WARNING_LENGTH` | 200 | Characters after trimming |
| `Attachment::MAX_BYTES` | 5 MiB | Hard upload and remote-body ceiling; `media.max_bytes` and `media.remote_max_bytes` may only lower it |
| `Attachment::MAX_ALT_TEXT_LENGTH` | 1000 | Characters after trimming |
| `Attachment::MAX_SOURCE_URL_LENGTH` | 512 | Longest stored remote media URL |
| `RemoteMediaFetcher::DEFAULT_TIMEOUT_SECONDS` | 10 | Default per-hop remote fetch timeout |
| `RemoteMediaFetcher::MAX_TIMEOUT_SECONDS` | 30 | Hard timeout ceiling |
| `RemoteMediaFetcher::DEFAULT_MAX_REDIRECTS` | 3 | Default redirect budget |
| `RemoteMediaFetcher::MAX_REDIRECTS` | 5 | Hard redirect ceiling |
| `RemoteMediaService::MAX_ATTACHMENTS` | 4 | Imported images per incoming post |
| `RemoteThreadFetcher::DEFAULT_MAX_DEPTH` | 5 | Default ancestors walked for a thread |
| `RemoteThreadFetcher::MAX_DEPTH` | 10 | Hard ancestor ceiling |
| `RemoteThreadFetcher::DEFAULT_MAX_REPLIES` | 20 | Default direct replies returned |
| `RemoteThreadFetcher::MAX_REPLIES` | 40 | Hard direct-reply ceiling |
| `RemoteThreadFetcher::DEFAULT_MAX_BYTES` | 1 MiB | Default thread object body limit |
| `RemoteThreadFetcher::MAX_BYTES` | 5 MiB | Hard thread object body ceiling |
| `RemoteThreadFetcher::DEFAULT_TIMEOUT_SECONDS` | 10 | Default per-hop thread timeout |
| `RemoteThreadFetcher::MAX_TIMEOUT_SECONDS` | 30 | Hard thread timeout ceiling |
| `RemoteThreadFetcher::DEFAULT_MAX_REDIRECTS` | 3 | Default thread redirect budget |
| `RemoteThreadFetcher::MAX_REDIRECTS` | 5 | Hard thread redirect ceiling |
| `Attachment::MAX_DIMENSION` | 10000 | Longest stored image side |
| `Attachment::MAX_PIXELS` | 40,000,000 | Decode guard against decompression bombs |
| `Post::MAX_ATTACHMENTS` | 4 | Attachments per post |
| `AtprotoPostRecord::MAX_TEXT_GRAPHEMES` | 300 | AT Protocol post text in graphemes |
| `AtprotoPostRecord::MAX_TEXT_BYTES` | 3000 | AT Protocol post text in bytes |
| `AtprotoOAuthSession::MAX_TOKEN_LENGTH` | 8192 | Longest stored access or refresh token |
| `AtprotoOAuthSession::MAX_CLIENT_ID_LENGTH` | 512 | Longest OAuth client id URL |
| `AtprotoOAuthSession::MAX_SCOPE_LENGTH` | 1024 | Longest granted scope string |
| `AtprotoSessionService::REFRESH_LEEWAY_SECONDS` | 60 | Refresh a session this many seconds before expiry |
| `XrpcAtprotoSessionRefresher::TIMEOUT_SECONDS` | 20 | Token-endpoint request timeout |
| `MediaService::DEFAULT_THUMBNAIL_MAX_DIMENSION` | 640 | Default longest thumbnail side |
| `TimelineService::DEFAULT_LIMIT` | 20 | Default page size |
| `TimelineService::MAX_LIMIT` | 40 | Page size ceiling |
| `OrderedCollection::DEFAULT_LIMIT` | 20 | Default page size of an actor collection |
| `OrderedCollection::MAX_LIMIT` | 40 | Page size ceiling of an actor collection |
| `FollowerService::DEFAULT_LIMIT` | 20 | Default follower page |
| `FollowerService::MAX_LIMIT` | 100 | Follower page ceiling |
| `FollowerService::MAX_ACTOR_LENGTH` | 255 | Indexed actor URL length |
| `FollowerService::MAX_INBOX_LENGTH` | 512 | Inbox URL length |
| `FollowingService::MAX_ACTOR_LENGTH` | 255 | Indexed actor URL length of an outgoing follow |
| `FollowingService::MAX_INBOX_LENGTH` | 512 | Inbox URL length of an outgoing follow |
| `InboxService::MAX_SKEW_SECONDS` | 300 | Allowed clock skew of a signed inbox request |
| `RemotePost::MAX_ID_LENGTH` | 512 | Stored activity/object id length |
| `RemotePost::MAX_ACTOR_LENGTH` | 255 | Stored remote actor id length |
| `RemotePost::MAX_WARNING_LENGTH` | 255 | Stored remote content-warning length |
| `InMemoryActivityLog::RETENTION_SECONDS` / `PdoActivityLog::RETENTION_SECONDS` | 86400 | How long an accepted activity id blocks a replay |
| `InMemoryJobQueue::MAX_ATTEMPTS` / `PdoJobQueue::MAX_ATTEMPTS` | 5 | Attempts before a dead letter |
| `InMemoryJobQueue::BASE_BACKOFF_SECONDS` / `PdoJobQueue::BASE_BACKOFF_SECONDS` | 30 | First retry delay |
| `InMemoryJobQueue::MAX_BACKOFF_SECONDS` / `PdoJobQueue::MAX_BACKOFF_SECONDS` | 3600 | Backoff ceiling |
| `PdoJobQueue::RESERVATION_SECONDS` | 300 | A reservation older than this may be claimed by another worker |
| `RandomIdGenerator::BYTES` | 12 | 96 bits of randomness |
| `RouteManifest::VERSION` | 1 | Export document version |
| `ApiVersion::BASE_PATH` | `/api/v1` | API prefix |

The queue and activity-log limits are shared by the in-memory and PDO
implementations; `PdoJobQueue` aliases the retry constants from
`InMemoryJobQueue` so a successful retry and a dead letter happen at the same
attempt in both. `PdoJobQueue::RESERVATION_SECONDS` only affects crash
recovery; it does not make a normal retry faster.

Changing a limit is a product decision, not a refactor: a client may render
"5000 characters" and a test asserts it. Change the constant, the test and the
docs together.

## Environment of a host

The core reads no environment variable itself. The standalone host is expected
to map at least:

```
ALOHASOCIAL_INSTANCE_URL=https://social.example
ALOHASOCIAL_INSTANCE_NAME=Aloha Social
```

Those names are a proposal for the not-yet-existing server repository, not a
contract of this package.
