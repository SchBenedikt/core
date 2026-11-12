# Mastodon-compatible API

The core ships a small, optional Mastodon-compatible read/write surface over
the **same** posts and repositories as `/api/v1`. It is additive: enabling it
does not change, replace or shadow `/api/v1`, and it is **off by default**.

This is not a second status store. A Mastodon status is a core post with an
API-shaped projection, so deleting a status through either API soft-deletes
the same row and a write is still validated, stored and delivered by
`PostService`.

## Status and scope

Locally implemented (core):

- `GET /instance`
- `GET /timelines/public`
- `GET /statuses/{id}`
- `POST /statuses`
- `POST /statuses/{id}/favourite`
- `POST /statuses/{id}/unfavourite`
- `GET /accounts/relationships`
- `POST /accounts/{id}/follow`
- `POST /accounts/{id}/unfollow`
- `POST /media`
- `PUT /media/{id}`
- `DELETE /media/{id}`
- `DELETE /statuses/{id}`
- `GET /accounts/{id}/statuses`
- `GET /accounts/verify_credentials`
- `GET /notifications`
- `GET /search`

The local subset covers public/unlisted/followers-only text posts, local poll
creation on an own status, local favourite/unfavourite bookkeeping, local
follow/unfollow and relationship bookkeeping, local notifications, media
upload, metadata update/delete and local media attachments. It deliberately
does **not** implement remote timelines, following a remote actor through this
compatibility prefix, remote favourite federation (outbound `Like`/`Undo` or
remote favourite counts), boosts, remote notifications or notification
dismissal, streaming, voting on or fetching remote polls, scheduled posts,
filters, followed tags, lists, OAuth authorization/token endpoints, remote
search, admin APIs or the full account/profile surface.
Local search over local accounts and statuses is implemented below; remote
search and hashtag search are external. Some of the remaining pieces are
locally implementable and remain on the roadmap; only the parts that need a
host or remote data are external. See the section at the end.

## Configuration and prefix

| Key | Default | Meaning |
| --- | --- | --- |
| `social/mastodon.api` | `false` | Enables the compatibility handlers and gate. |
| `social/mastodon.prefix` | `/api/mastodon/v1` | Path prefix for all seventeen routes. |

`mastodon.prefix` must be a non-root path without a trailing slash, query,
fragment, template, empty/parent segment or unsupported characters. It must
not be `/api/v1` or a child of it, and it must not overlap `/healthz`,
`/inbox`, `/@` or `/.well-known`. A bad prefix fails at boot with a
`ConfigurationException` instead of producing a collision.

With the switch off, **every** compatibility route answers `404
mastodon_api_disabled` — including authenticated writes. The gate runs before
authentication and scope checks, so a disabled feature does not disclose a
different result to a caller who supplies a token. The routes stay in the
manifest and OpenAPI export so a host can serve a stable contract; enabling the
feature is a configuration change, not a route change.

The custom prefix is used by both `Application::assemble()` and
`Routes::manifest($prefix)`. `bin/export-routes` and `bin/export-openapi` use
the default prefix unless a caller passes one.

## Authentication and scopes

The host authenticates the token and puts the local user id on
`Request::$userId` and the token scopes on `Request::$scopes`. The core never
issues tokens and does not know a Mastodon OAuth app or client secret.

| Route | Auth | Required scope | Rate limit |
| --- | --- | --- | --- |
| `GET {prefix}/instance` | no | — | 120 / 60 s |
| `POST {prefix}/media` | yes | `write:media` | 20 / 900 s |
| `PUT {prefix}/media/{id}` | yes | `write:media` | 30 / 900 s |
| `DELETE {prefix}/media/{id}` | yes | `write:media` | 30 / 900 s |
| `GET {prefix}/timelines/public` | no | — | 60 / 60 s |
| `GET {prefix}/statuses/{id}` | no | — | 120 / 60 s |
| `POST {prefix}/statuses` | yes | `write:statuses` | 30 / 900 s |
| `DELETE {prefix}/statuses/{id}` | yes | `write:statuses` | 30 / 900 s |
| `POST {prefix}/statuses/{id}/favourite` | yes | `write:favourites` | 30 / 900 s |
| `POST {prefix}/statuses/{id}/unfavourite` | yes | `write:favourites` | 30 / 900 s |
| `GET {prefix}/accounts/relationships` | yes | `read:follows` | 60 / 60 s |
| `POST {prefix}/accounts/{id}/follow` | yes | `write:follows` | 30 / 900 s |
| `POST {prefix}/accounts/{id}/unfollow` | yes | `write:follows` | 30 / 900 s |
| `GET {prefix}/accounts/{id}/statuses` | no | — | 60 / 60 s |
| `GET {prefix}/accounts/verify_credentials` | yes | `read:accounts` | 60 / 60 s |
| `GET {prefix}/notifications` | yes | `read:notifications` | 60 / 60 s |
| `GET {prefix}/search` | yes | `read:search` | 60 / 60 s |

A parent scope grants its children (`read` grants `read:accounts`,
`read:follows`, `read:notifications` and `read:search`, `write` grants
`write:favourites`, `write:follows` and `write:media`); a granular scope does
not grant its parent.
`*` grants everything. A token with too few scopes gets `403
insufficient_scope`. The host still owns bearer parsing, token
expiry/revocation and the actual rate limiter; the route manifest only declares
the budget, exactly like the rest of the core API.

The local-account directory, when supplied, is checked for
`verify_credentials`, status creation, favourite/unfavourite, follow/unfollow,
relationships, notifications, media management, account statuses and account
search. Without a directory the core cannot distinguish local from remote
account ids; a host that enables this API should always pass one.

## Instance metadata

`GET {prefix}/instance` is public, rate-limited at `120/60`, and projects the
existing instance configuration and local stores instead of returning fixed
placeholder values. The contact and registration fields are optional config:

| Key | Field | Default |
| --- | --- | --- |
| `social/instance.description` | `description`, `short_description` | empty |
| `social/instance.version` | the Aloha Social part of `version` | `0.1.0` |
| `social/instance.contact_email` | `email` (`instance.email` is a fallback) | empty |
| `social/instance.contact_account` | `contact_account` | `null` |
| `social/instance.registrations` | `registrations` | `false` |
| `social/instance.approval_required` | `approval_required` | `true` |
| `social/instance.invites_enabled` | `invites_enabled` | `false` |
| `social/instance.languages` | `languages` (comma-separated or JSON array) | `[]` |
| `social/instance.thumbnail` | `thumbnail` | empty |

The `version` field keeps the Mastodon-compatible prefix and appends the
configured core version. `configuration.statuses`, `configuration.media_attachments`
and `configuration.polls` continue to reflect the real limits in
`Post`, `Attachment` and `Poll`; they are not configurable down through this
endpoint. `contact_account` is projected as the normal Mastodon account only
when it is configured and, if a `LocalActorDirectory` is supplied, that
account exists locally. A stale value therefore cannot advertise an account
that cannot be opened; without a directory the host remains responsible for
the configured id.

`stats` is derived from three optional capabilities over the existing stores:
`LocalActorCount` for `user_count`, `PostCount` for live `status_count` and
`RemoteDomainCount` for distinct live remote-post hosts. A store that does not
implement a capability reports `0` for that field; it is not an error, and the
endpoint does not fall back to an unbounded scan. The in-memory directory, both
post repositories and both remote-post repositories implement them, so the
standard assemblies report real values. The core `GET /api/v1/instance` embeds
the same description and statistics. There is no instance/statistics table,
migration or cleanup job: the values are read-only projections.

## Reading statuses

`GET /timelines/public` and `GET /accounts/{id}/statuses` return a JSON array
of status objects. `GET /statuses/{id}` returns one object. The mapping is:

- `id` is the core post id; it is opaque to clients.
- `account.id`/`acct`/`username` is the local user id. A full profile is
  host-owned; display name, avatar and counts are minimal defaults.
- `visibility`: `public`, `unlisted`, `private` (core followers-only) or
  `direct` (not writable locally).
- `content` is escaped text in a single `<p>`; `spoiler_text` is the content
  warning. A content warning implies `sensitive: true`.
- `media_attachments` projects the existing attachments, their alt text and
  original/thumbnail URLs. No new blob or media row is created by this API.
- `favourites_count` is the number of local accounts that favourited the post;
  `favourited` is true only when the authenticated viewer owns a favourite.
  The public timeline is unauthenticated, so it always reports `favourited`
  false while still reporting the count.
- `poll` is `null` when the status has no poll, otherwise the Mastodon poll
  object. Its options and counts come from the existing status row and the
  poll tables; no separate status model is created. There is no vote endpoint
  yet, so every option and `votes_count`/`voters_count` is `0`, `voted` is
  `false`, and `own_votes`/`emojis` are empty. `expired` is computed from
  `expires_at` and the clock, so it flips without a scheduled job.
- `uri`/`url` point at the core post URL; `created_at` is the stored publish
  time.

A non-public status is readable only by its author. For everyone else it is
`404 not_found`, like the core API; the status route does not leak existence.
Account statuses are public/unlisted only, including when the account asks for
its own list.

## Writing and deleting

`POST /statuses` accepts `application/json` or
`application/x-www-form-urlencoded`:

```json
{
  "status": "hello",
  "visibility": "public",
  "spoiler_text": "optional",
  "sensitive": false,
  "media_ids": ["existing-attachment-id"],
  "poll": {
    "options": ["Yes", "No"],
    "expires_in": 3600,
    "multiple": false,
    "hide_totals": false
  }
}
```

`private` maps to followers-only. `direct` is refused with `422
unsupported_parameter` because the core has no recipient model yet. Empty
content, over-limit text, more than four attachments and an attachment not
owned by the caller are refused by `PostService` with `422
validation_failed` and `error.fields`. `in_reply_to_id` is also refused with
`422 unsupported_parameter` in this slice.

`poll` is optional and is stored atomically with the status. It accepts two to
four `options`, each after trimming one to 50 characters, plus exactly one of
`expires_in` (integer seconds, 300–604800) or `expires_at` (ISO date/time). The
resulting expiry must be between 300 and 604800 seconds from the request time;
when both forms are present, `expires_in` wins. `multiple` and `hide_totals`
default to `false` and accept JSON booleans, `0`/`1` and the usual
`true`/`false`/`yes`/`no`/`on`/`off` form values. Invalid options, expiry or
booleans are `422 validation_failed`; the handler validates before calling
`PostService`, so a rejected poll creates no post, attachment link or poll row.
The same route then returns the status with `poll` populated, and `GET
/statuses/{id}`, timelines, account statuses and search return the same object.
Poll creation currently has no local vote endpoint and does not federate an
ActivityPub `Question`; those are separate slices.

`DELETE /statuses/{id}` soft-deletes the caller's status and returns `204`.
Another account's status, a missing status or an already deleted status is
`404 not_found`; the ownership check happens in `PostService`, not in a
handler copy.

### Favouriting statuses

`POST /statuses/{id}/favourite` records the signed-in local account's
favourite and returns the same Mastodon status shape with `favourited: true`
and an incremented `favourites_count`. `POST /statuses/{id}/unfavourite`
removes only that account's favourite and returns `favourited: false`.

Both endpoints require `write:favourites`, declare a `30/900` rate limit and
are idempotent: repeating a favourite returns the existing count without a
second row, and unfavouriting an absent favourite is a successful no-op. The
composite `(user_id, post_id)` primary key in `social_favourite` is the
idempotency boundary, so no client key is needed. The local-account directory
and the same visibility rule as status reads apply: a missing, deleted,
private or otherwise invisible status is `404 not_found`, and a non-local
account is refused before any write.

```json
{
  "id": "…",
  "favourites_count": 1,
  "favourited": true,
  "…": "the usual status fields"
}
```

An assembly without a `FavouriteRepository` answers `503
favourites_not_configured` instead of silently dropping the write. The normal
`Application::inMemory()`/`Application::forHost()` assemblies always supply
one.

### Following accounts and relationships

`POST /accounts/{id}/follow` follows a **local** account from the signed-in
local account. The target id is the local directory id used by the rest of
this API, not a remote actor URL. A self-follow is `422 validation_failed`
with `error.fields.id`; an unknown or non-local target is `404 not_found`.
The write is idempotent: repeating the follow returns the same relationship
and does not create a second edge. `POST /accounts/{id}/unfollow` removes only
the caller's edge and is a successful no-op when the account was not followed.

The edge is stored in the existing incoming graph (`social_follower`) with
`is_local = 1`, because the same graph already answers follower counts and the
public followers collection. Local edges are excluded from
`FollowerRepository::inboxesFor()`, so a local follow never queues a remote
delivery or a signed `Follow`. A local follow appears in the target's public
followers collection and in the caller's public following collection; remote
follow edges stay in `social_following` and count through the existing
outgoing service. The authenticated core home timeline
(`GET /api/v1/timelines/home`) also includes live local posts by accounts the
reader follows locally; blocking one of those accounts hides it from the
reader without deleting the follow edge. This is a local relationship only —
following a remote actor through this compatibility prefix is not part of the
slice; the core `POST /api/v1/following` remains the federation path.

`GET /accounts/relationships` accepts either a scalar `id` or repeated
`id[]` parameters:

```http
GET {prefix}/accounts/relationships?id[]=bob&id[]=carol
```

It returns one object per requested id, in request order, without duplicates.
At most 100 unique ids are accepted; a missing or empty `id` is `422
validation_failed`, and any unknown local id makes the whole request `404
not_found` so the response cannot silently omit a target. The fields follow
the Mastodon relationship shape:

```json
{
  "id": "bob",
  "following": true,
  "showing_reblogs": true,
  "notifying": false,
  "languages": [],
  "followed_by": false,
  "blocking": false,
  "blocked_by": false,
  "muting": false,
  "muting_notifications": false,
  "requested": false,
  "domain_blocking": false,
  "endorsed": false,
  "note": ""
}
```

`following` and `followed_by` are true only for local edges in this slice;
`blocking` reads the existing per-user block repository. Relationship reads
and the two writes require a `LocalRelationshipService` and a local account
directory. `Application::inMemory()`/`Application::forHost()` create the
service when a directory is supplied; an assembly without one answers `503
follows_not_configured` rather than returning an empty relationship.

### Reading notifications

`GET {prefix}/notifications` returns the signed-in local account's
notifications, newest first. A local favourite by another account creates one
`favourite` notification for the post author; a local or remote follow creates
one `follow` notification for the local target. Repeating the same favourite,
follow or Follow activity does not create a second row. The API does not
notify an account about its own actions and does not create a notification for
a post the caller cannot see.

```json
{
  "id": "notification-8f14e45fceea167a5a36dedd4bea2543",
  "type": "favourite",
  "created_at": "2026-11-11T10:00:00.000Z",
  "account": {
    "id": "alice",
    "username": "alice",
    "acct": "alice",
    "url": "https://social.example/@alice",
    "…": "the usual account fields"
  },
  "status": {
    "id": "…",
    "…": "the usual status fields"
  }
}
```

`account` is the actor who caused the event: a local account id for a local
favourite or follow, and the absolute actor URL for a remote follower. A
remote account is projected with the URL path as `username`, `username@host`
as `acct`, the actor URL as `url`, and zero counts; the core does not fetch a
remote profile just to render a notification. `status` is present for a
favourite and omitted for a follow. It is also omitted if the post was deleted
or is no longer visible to the recipient, so a notification never exposes a
tombstone. The id is derived from recipient, type, actor and post, which is the
idempotency boundary; no random notification id is generated.

The endpoint accepts `types[]` and `exclude_types[]`, each repeatable. Known
local types are `favourite` and `follow`; unknown names are ignored for
forward compatibility. If a non-empty `types[]` contains no known type, the
result is an empty array. The filters apply before the page limit.

`limit` defaults to 20 and is clamped to 1–40. `max_id` returns notifications
older than the cursor, `since_id` (or `min_id`) returns newer ones, and a
notification id belongs to exactly one recipient: a cursor that exists for a
different account is `404 not_found`, not someone else's page. `max_id` cannot
be combined with `since_id`/`min_id`. A non-empty page carries `Link: <…
?limit=…&max_id=…>; rel="next"` and preserves active type filters.

A manually assembled service without a `NotificationRepository` answers `503
notifications_not_configured` instead of returning an empty list. The standard
`Application::inMemory()`/`Application::forHost()` assemblies always supply
one. Reading notifications does not mark them as read; the core has no unread
or dismissal state in this slice.

### Searching local accounts and statuses

`GET {prefix}/search` implements the Mastodon v2 search object over local data
only:

```http
GET {prefix}/search?q=hello&type=statuses&limit=20&following=true
```

```json
{
  "accounts": [],
  "statuses": [{"id": "…", "content": "<p>hello</p>", "…": "the usual status fields"}],
  "hashtags": []
}
```

`q` is required and non-empty (`422 validation_failed`). `type` is optional
and accepts `accounts`, `statuses` or `hashtags`; any other value is `422
validation_failed`. Without `type`, all three collections are returned.
`hashtags` is always an empty array because the core has no hashtag model.
Status matches are case-insensitive over live, public/unlisted
`social_post.content`; private, direct and soft-deleted posts never appear,
and the projected status keeps the normal viewer flags and attachment shape.
Account matches are case-insensitive over the host's local account directory
and are projected with `MastodonPresenter::account`.

`following=true` limits both collections to local accounts the signed-in user
follows (and, for statuses, to posts by those accounts). It does not follow or
resolve anyone. `limit` defaults to 20 and is clamped to 1–40; `offset`
defaults to 0 and is clamped to 0–1000, so search uses offset pagination rather
than the id cursor of timelines. `resolve` and `exclude_unreviewed` are
accepted for client compatibility but have no local effect: the endpoint never
fetches a remote status or profile and has no review queue. `read:search` is
required; a parent `read` token also grants it. The full result object is
returned even when one collection is empty.

The two capabilities are optional ports over the existing stores:
`PostSearchRepository` and `LocalActorSearch`. The standard
`Application::inMemory()`/`Application::forHost()` assemblies wire these when
the supplied post store and account directory implement them. A host whose
post store does not implement search, or whose directory has no search
capability, gets `503
search_not_configured` when the affected collection is requested. Status
search needs no new table or index; a host with a large post table can add its
own index or search adapter behind the same port without changing the route.
Search is read-only, so it creates no rows and has no cleanup job.

### Uploading media

`POST /media` accepts `multipart/form-data` with a `file` field and an optional
`description` field, the same envelope as `POST /api/v1/media`. `alt_text` is
accepted as a fallback for clients that used the core name. The bytes are
validated and stored by the same `MediaService`: JPEG, PNG and WebP up to 5
MiB and 40 megapixels, with a bounded thumbnail. `focus` is ignored because the
core has no crop metadata. A successful upload answers `200` with a Mastodon
attachment:

```json
{
  "id": "…",
  "type": "image",
  "url": "https://social.example/api/v1/media/…",
  "preview_url": "https://social.example/api/v1/media/…?variant=thumbnail",
  "remote_url": null,
  "preview_remote_url": null,
  "text_url": null,
  "meta": {"original": {"width": 1200, "height": 800,
    "size": "1200x800", "aspect": 1.5}, "small": {…}},
  "description": "A cat on a keyboard",
  "blurhash": null
}
```

Use the returned `id` in `media_ids` on `POST /statuses`. A rejected upload
answers `400 invalid_multipart`, `413 payload_too_large`, `422
validation_failed` with `error.fields.file` or `error.fields.description`, or
`503 media_not_configured`/`media_storage_unavailable`; it leaves neither a
metadata row nor a blob. An instance without a `BlobStore`-backed
`MediaService` cannot serve this route and answers `503 media_not_configured`.

An optional `Idempotency-Key` makes a retry safe. The core derives a
deterministic internal `source_url` from the local user and the key and passes
it to `MediaService`, whose existing source-URL lookup returns the original
attachment before writing again. The key is scoped to the user, a repeat
returns the same attachment with no second row or blob, and a different key
creates a distinct attachment. A failed first attempt stores nothing, so a
retry can succeed. This needs no second table or cleanup job.

### Updating and deleting media

`PUT /media/{id}` updates the description of an owned, unattached attachment.
It accepts `application/json`, `application/x-www-form-urlencoded` and
`multipart/form-data`, reuses the same multipart parser as upload, and returns
the usual Mastodon attachment shape. `description` is optional: omitting it
leaves the current value unchanged, while an empty value clears it. `alt_text`
is accepted as a fallback. `focus` is ignored because the core has no focal
point metadata, and `file`/`thumbnail` are refused with `422
unsupported_parameter` rather than silently discarded.

Only an attachment owned by the caller and not linked to any local post can be
updated; a missing, foreign or attached id is `404 not_found`. The bytes and
storage keys never change, and no new row is created.

`DELETE /media/{id}` removes an owned attachment that is not linked to a post,
including its original and thumbnail blobs, and answers the Mastodon empty
object with status `200`:

```json
{}
```

An attached attachment is refused with `422 validation_failed` and the message
`Media attachment is currently used by a status.`; a missing or foreign id is
`404 not_found`. Both routes require `write:media`, declare a `30/900` rate
limit and are unavailable before auth when `social/mastodon.api` is off. A
soft-deleted post still counts as a link: its `social_post_attachment` row
survives the tombstone, so a host that wants to reclaim the media must remove
that link in its own cleanup path.

### Status idempotency

Send `Idempotency-Key: <client-key>` on `POST /statuses`. The core derives a
deterministic post id from the local user id and the key and passes it to
`PostService`. The first request inserts; a retry hits the unique post id,
re-reads that row and returns the same status instead of inserting a second
one. There is no extra idempotency table and no cleanup job. The key is
scoped to the user, so two users may use the same client key.

Repeating a key with different content returns the original status, as an
idempotency key should. Poll rows are part of the same transaction and are
returned from the original post on a retry, so they are not duplicated. A
failed validation or storage attempt writes no row and no delivery job; a later
retry may succeed. Keys are meant to be unique per logical write, not per
request body.

## Pagination

`limit` defaults to 20 and is clamped to 1–40. A value outside the range is
first accepted as an integer and then clamped; a non-integer falls back to the
default.

- `max_id` returns entries older than that cursor, using the stored time and
  id as a tie-break so several posts in the same second cannot repeat or
  disappear.
- `since_id` returns entries newer than that cursor.
- `min_id` is accepted as the same newer-than cursor when `since_id` is
  absent.
- `max_id` cannot be combined with `since_id`/`min_id`; that is `422
  validation_failed`.
- An unknown cursor id is `404 not_found`; a notification cursor must belong to
  the signed-in recipient.
- Search uses a numeric `offset` instead of a cursor: it is clamped to 0–1000
  and applied after the visibility/author filter, so a page never contains a
  non-public or deleted status.

A non-empty page carries `Link: <…?limit=…&max_id=…>; rel="next"`. Clients can
follow it until the page is empty. This uses the existing post or notification
repository's id-aware cursor; the API does not keep a per-client timeline.

## Errors

The normal core JSON error shape applies. The compatibility-specific codes are:

| Code | Status | When |
| --- | --- | --- |
| `mastodon_api_disabled` | 404 | `social/mastodon.api` is off |
| `insufficient_scope` | 403 | The token lacks the route's required scope |
| `favourites_not_configured` | 503 | The assembly has no `FavouriteRepository` |
| `notifications_not_configured` | 503 | The assembly has no `NotificationRepository` |
| `notifications_unavailable` | 503 | The notification table is missing or cannot be read; run the schema migration |
| `follows_not_configured` | 503 | The assembly has no local account directory or `LocalRelationshipService` |
| `search_not_configured` | 503 | The assembly has no `PostSearchRepository` or `LocalActorSearch` for the requested collection, or no relationship service for `following=true` |
| `instance_not_configured` | 503 | `social/instance.url` is missing; a local follow needs it to derive actor URLs |
| `media_not_configured` | 503 | Media upload has no `BlobStore`-backed `MediaService` |
| `media_storage_unavailable` | 503 | A validated upload could not be written to the blob store |
| `unsupported_parameter` | 422 | `direct` visibility, `in_reply_to_id`, or replacing media bytes (`file`/`thumbnail`) in this slice |
| `validation_failed` | 422 | Missing/invalid status fields, poll options/expiry, media ids, relationship ids, search `q`/`type` or a self-follow; see `error.fields` |
| `not_found` | 404 | Missing/private status, unknown cursor, unknown local account or unknown relationship target |

Authentication failures use the existing `401 authentication_required`; the
host's limiter should use `429` with its own retry metadata. The core does not
invent a second error shape for the compatibility surface.

## Database and cleanup

Statuses live in `social_post` / `social_post_attachment`; uploaded images use
`social_attachment` and the same `BlobStore` as `/api/v1/media`. Polls add
`social_poll` and ordered `social_poll_option` rows from `PollSchema`, keyed by
`post_id` and written in the same transaction as the post. Existing hosts must
create both tables before accepting a `poll` parameter or serving statuses
whose repository reads the poll table; a fresh host gets them from
`PollSchema::create()`. Poll rows and their options are removed with the post's
hard-delete cleanup, while a soft-deleted post leaves them as a tombstone like
favourites and attachments. There is no vote or expiry row: `expired` is
derived at read time. Status idempotency uses the primary key and a
deterministic post id; media
idempotency uses the existing unique `source_url` column with a deterministic
internal key. Media update changes only `social_attachment.alt_text`; media
delete removes that row and both blobs, but only while no `social_post_attachment`
row references it. Neither operation adds a table or migration. Local
favourites use the new `social_favourite` table from
`FavouriteSchema`. Local follow edges add one column to the existing
`social_follower` table from `FollowerSchema`:

```sql
CREATE TABLE social_favourite (
  user_id VARCHAR(255) NOT NULL,
  post_id VARCHAR(64) NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  PRIMARY KEY (user_id, post_id)
);
CREATE INDEX social_favourite_post ON social_favourite (post_id);
CREATE INDEX social_favourite_user ON social_favourite (user_id, created_at);
```

```sql
ALTER TABLE social_follower ADD COLUMN is_local SMALLINT NOT NULL DEFAULT 0;
```

Existing follower rows default to `is_local = 0`, which is exactly the old
remote-delivery meaning, so the migration needs no backfill. Only
`POST /accounts/{id}/follow` writes `is_local = 1`; unfollow removes the row.
An existing host must add the column before exposing the follow routes; a
fresh host gets it from `FollowerSchema::create()`. A host that leaves the old
schema in place will fail on follower reads and on follow/unfollow. There is
no cleanup job for the marker: it is part of the edge and disappears with it.

```sql
CREATE TABLE social_poll (
  post_id VARCHAR(64) NOT NULL PRIMARY KEY,
  multiple SMALLINT NOT NULL,
  hide_totals SMALLINT NOT NULL,
  expires_at VARCHAR(20) NOT NULL,
  votes_count INTEGER NOT NULL DEFAULT 0,
  voters_count INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX social_poll_expiry ON social_poll (expires_at);

CREATE TABLE social_poll_option (
  post_id VARCHAR(64) NOT NULL,
  position INTEGER NOT NULL,
  title VARCHAR(255) NOT NULL,
  votes_count INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id, position)
);
CREATE INDEX social_poll_option_post ON social_poll_option (post_id);
```

```sql
CREATE TABLE social_notification (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  recipient_id VARCHAR(255) NOT NULL,
  actor_id VARCHAR(255) NOT NULL,
  type VARCHAR(32) NOT NULL,
  post_id VARCHAR(64) NULL,
  created_at VARCHAR(20) NOT NULL
);
CREATE INDEX social_notification_timeline
  ON social_notification (recipient_id, created_at, id);
```

An existing host must create `social_favourite` through its migration path
before exposing the two favourite routes; there is no data backfill. A fresh
host can run `FavouriteSchema::create()`. An existing host must likewise create
`social_notification` with `NotificationSchema::statements()` or
`NotificationSchema::create()` before enabling the notification route; the
standard `forHost()` assembly constructs the SQL repository, but it does not
alter an existing database. Recording is best-effort: while the table is
missing, a favourite or follow still succeeds, the notification failure is
logged, and repeating the event after the migration can heal the row. The
notification listing route answers `503 notifications_unavailable` until the
table exists.
There is no backfill from old favourites or followers: only events recorded
after the table exists appear. Deleting a post
follows the normal soft-delete path and deliberately leaves favourite,
poll, option and notification rows for an explicit host cleanup; the post is
not visible, so its count and status are not served. Deleting a follower or
unfavouriting removes the interaction, not its historical notification.
Notification rows can be removed by recipient or by age in a host cleanup job;
no read/dismiss state is stored. Poll cleanup should delete options first or
rely on the host's `ON DELETE CASCADE`; the core schema does not add a foreign
key. Attachment rows and blobs remain governed by the existing media
cleanup policy, not duplicated here.

## OpenAPI and tests

The seventeen routes are in the manifest with auth, scope and rate-limit metadata.
`bin/export-openapi` includes them with the `mastodon` tag and
`x-required-scopes`. `MastodonApiTest` drives the real kernel through instance metadata,
configured contact/registration fields and local stats (including stale contact
accounts and the core projection), media upload (success, description, media-in-status, auth, scope, disabled,
missing/invalid/corrupt/oversized file, no orphan rows or blobs, idempotency),
media update (JSON/form/multipart, absent/empty description, ownership,
attached refusal, binary refusal) and delete (empty object, blob cleanup,
attached refusal, foreign/missing ids, scopes and disabled feature),
timeline, create (JSON and form), poll creation (JSON/form, `expires_in` and
`expires_at`, expiry flip, options/expiry/boolean validation with no partial
rows, idempotent retry, instance limits), read, local favourite/unfavourite (counts,
viewer flags, repeat idempotency, absent-unfavourite no-op, auth, scope,
deleted status and custom prefix), local follow/unfollow (relationship flags,
idempotency, self-follow, unknown target, home-timeline visibility, scopes,
disabled feature), and
relationship reads (scalar and `id[]`, deduplication, missing/empty input,
unknown ids and the 100-id limit), notifications (favourite/follow creation,
repeat idempotency, historical survival, type filters, pagination, cursor
ownership, auth/scope/disabled), local search (account/status matches, type
filters, following filter, offset pages, private/deleted exclusion, missing
`q`/invalid type, auth/scope/disabled and missing capability), account
statuses, verify, delete and cursor cases. `FollowerRepositoryTestCase` is the shared contract for the in-memory
and PDO stores and now covers local edges, their exclusion from delivery
inboxes and their follower counts. `FavouriteRepositoryTestCase` covers pair
uniqueness, ownership, batch counts and removal. `MastodonCompatibilityTest`
covers prefix validation, scope matching and route metadata, including the
three relationship routes, the notification route and search. The post
repository contract still covers the id-aware cursor used by the API, and now
also the `PostSearchRepository` match, visibility, author-filter and offset
contract for both the in-memory and PDO implementations; the in-memory
actor-directory search is covered directly.

## What remains external

Turning this into a complete Mastodon server is intentionally out of scope:

- OAuth authorization, token issuance/refresh/revocation and app registration
  belong to the host or a dedicated identity service.
- Remote statuses, public/remote timelines, favourite federation (outbound
  `Like`/`Undo` and remote counts), boosts, bookmarks, remote notifications,
  streaming and remote search need remote data the core does not fetch for
  this surface. Local notification listing and local status/actor search are
  done above.
- Notification dismissal/unread state and narrower local account/profile
  endpoints are locally implementable and remain on the roadmap; they are not
  host-side prerequisites. Instance metadata and statistics are done above.
  Remote relationship APIs, remote polls (fetching, voting or federating a
  `Question`), domain blocks that would need a remote moderation feed, and
  remote search remain external; the local follow/unfollow, relationship,
  notification, search, poll-creation and instance views above are done.
- Following a remote actor through the compatibility prefix and remote
  follower/following listings are not part of this slice; moderation/admin
  APIs are also external. The core `/api/v1/following` path remains the
  federation route.
- The host must map bearer tokens to `userId`/`scopes`, enforce the declared
  rate limits and decide whether to expose the custom prefix publicly.

These remain tracked in the roadmap as host/integration work; the local
routes, mapping, validation, pagination, idempotency and error behavior above
are complete and tested.
