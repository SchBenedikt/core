# Routes

The manifest in [`src/Contract/Routes.php`](../../src/Contract/Routes.php) is the
source of truth; `php bin/export-routes` prints it as JSON and
`php bin/export-openapi` renders the same routes as OpenAPI 3.1. Paths are absolute
and versioned under `/api/v1` except the liveness probe, the protocol
endpoints (`/inbox`, `/inbox/shared`, `/@{handle}`, `/.well-known/webfinger`)
and the optional, disabled-by-default Mastodon compatibility prefix (which
defaults to `/api/mastodon/v1`). There are 125 routes in total; 53 use the
compatibility prefix.

The top-level `version` in the JSON export is the **export format** version
(`RouteManifest::VERSION`, currently `1`), not the core package version. The
package version is `Version::CURRENT` in [`src/Version.php`](../../src/Version.php);
see the [compatibility matrix](../guides/host-integration.md#compatibility).

| Name | Method | Path | Auth | Rate limit |
| --- | --- | --- | --- | --- |
| `health` | `GET` | `/healthz` | no | — |
| `instance.get` | `GET` | `/api/v1/instance` | no | — |
| `media.upload` | `POST` | `/api/v1/media` | yes | 20 / 900 s |
| `media.get` | `GET` | `/api/v1/media/{id}` | no | 240 / 60 s |
| `posts.create` | `POST` | `/api/v1/posts` | yes | 30 / 900 s |
| `posts.get` | `GET` | `/api/v1/posts/{id}` | no | — |
| `posts.update` | `PATCH` | `/api/v1/posts/{id}` | yes | 30 / 900 s |
| `quote-authorizations.get` | `GET` | `/api/v1/quote-authorizations/{id}` | no | 120 / 60 s |
| `quote-authorizations.revoke` | `DELETE` | `/api/v1/quote-authorizations/{id}` | yes | 30 / 900 s |
| `posts.boost` | `POST` | `/api/v1/posts/{id}/boost` | yes | 30 / 900 s |
| `posts.unboost` | `DELETE` | `/api/v1/posts/{id}/boost` | yes | 60 / 900 s |
| `atproto.publish` | `POST` | `/api/v1/posts/{id}/atproto` | yes | 10 / 900 s |
| `atproto.publication` | `GET` | `/api/v1/posts/{id}/atproto` | yes | 120 / 60 s |
| `threads.get` | `GET` | `/api/v1/remote-posts/{id}/thread` | yes | 60 / 60 s |
| `remote-posts.get` | `GET` | `/api/v1/remote-posts/{id}` | yes | 120 / 60 s |
| `remote-polls.get` | `GET` | `/api/v1/remote-polls/{id}` | yes | 120 / 60 s |
| `remote-polls.vote` | `POST` | `/api/v1/remote-polls/{id}/votes` | yes | 30 / 900 s |
| `remote-posts.like` | `POST` | `/api/v1/remote-posts/{id}/like` | yes | 30 / 900 s |
| `remote-posts.unlike` | `DELETE` | `/api/v1/remote-posts/{id}/like` | yes | 60 / 900 s |
| `remote-posts.react` | `POST` | `/api/v1/remote-posts/{id}/reactions` | yes | 30 / 900 s |
| `remote-posts.unreact` | `DELETE` | `/api/v1/remote-posts/{id}/reactions/{emoji}` | yes | 60 / 900 s |
| `reports.create` | `POST` | `/api/v1/reports` | yes | 20 / 900 s |
| `posts.delete` | `DELETE` | `/api/v1/posts/{id}` | yes | — |
| `scheduled-posts.list` | `GET` | `/api/v1/scheduled-posts` | yes | 60 / 60 s |
| `scheduled-posts.create` | `POST` | `/api/v1/scheduled-posts` | yes | 30 / 900 s |
| `scheduled-posts.get` | `GET` | `/api/v1/scheduled-posts/{id}` | yes | 120 / 60 s |
| `scheduled-posts.update` | `PATCH` | `/api/v1/scheduled-posts/{id}` | yes | 30 / 900 s |
| `scheduled-posts.delete` | `DELETE` | `/api/v1/scheduled-posts/{id}` | yes | 30 / 900 s |
| `filters.list` | `GET` | `/api/v1/filters` | yes | 60 / 60 s |
| `filters.create` | `POST` | `/api/v1/filters` | yes | 30 / 900 s |
| `filters.get` | `GET` | `/api/v1/filters/{id}` | yes | 120 / 60 s |
| `filters.update` | `PATCH` | `/api/v1/filters/{id}` | yes | 30 / 900 s |
| `filters.delete` | `DELETE` | `/api/v1/filters/{id}` | yes | 30 / 900 s |
| `timelines.public` | `GET` | `/api/v1/timelines/public` | no | — |
| `timelines.tag` | `GET` | `/api/v1/timelines/tag/{tag}` | no | — |
| `timelines.followed_tags` | `GET` | `/api/v1/timelines/followed_tags` | yes | — |
| `timelines.home` | `GET` | `/api/v1/timelines/home` | yes | — |
| `followed_tags.list` | `GET` | `/api/v1/followed_tags` | yes | — |
| `followed_tags.follow` | `POST` | `/api/v1/followed_tags` | yes | — |
| `followed_tags.unfollow` | `DELETE` | `/api/v1/followed_tags/{tag}` | yes | — |
| `followers.list` | `GET` | `/api/v1/followers` | yes | 120 / 60 s |
| `followers.follow` | `POST` | `/api/v1/followers` | yes | 30 / 900 s |
| `followers.unfollow` | `DELETE` | `/api/v1/followers/{id}` | yes | 60 / 900 s |
| `following.follow` | `POST` | `/api/v1/following` | yes | 30 / 900 s |
| `following.unfollow` | `DELETE` | `/api/v1/following/{id}` | yes | 60 / 900 s |
| `blocks.list` | `GET` | `/api/v1/blocks` | yes | 120 / 60 s |
| `blocks.block` | `POST` | `/api/v1/blocks` | yes | 30 / 900 s |
| `blocks.unblock` | `DELETE` | `/api/v1/blocks/{id}` | yes | 60 / 900 s |
| `accounts.show` | `GET` | `/api/v1/accounts/{id}` | no; an optional token adds the viewer relationship | 120 / 60 s |
| `accounts.follow` | `POST` | `/api/v1/accounts/{id}/follow` | yes | 30 / 900 s |
| `accounts.unfollow` | `DELETE` | `/api/v1/accounts/{id}/follow` | yes | 60 / 900 s |
| `notifications.list` | `GET` | `/api/v1/notifications` | yes | 60 / 60 s |
| `notifications.unread_count` | `GET` | `/api/v1/notifications/unread_count` | yes | 60 / 60 s |
| `notifications.dismiss` | `POST` | `/api/v1/notifications/{id}/dismiss` | yes | 30 / 900 s |
| `notifications.clear` | `POST` | `/api/v1/notifications/clear` | yes | 30 / 900 s |
| `notifications.policy` | `GET` | `/api/v1/notifications/policy` | yes | 60 / 60 s |
| `notifications.policy.update` | `PATCH` | `/api/v1/notifications/policy` | yes | 30 / 900 s |
| `notifications.requests` | `GET` | `/api/v1/notifications/requests` | yes | 60 / 60 s |
| `notifications.requests.accept` | `POST` | `/api/v1/notifications/requests/{id}/accept` | yes | 30 / 900 s |
| `notifications.requests.dismiss` | `POST` | `/api/v1/notifications/requests/{id}/dismiss` | yes | 30 / 900 s |
| `notifications.requests.revoke` | `DELETE` | `/api/v1/notifications/requests/{id}` | yes | 30 / 900 s |
| `search` | `GET` | `/api/v1/search` | no; an optional token applies the viewer's blocks and mutes | 60 / 60 s |
| `trends` | `GET` | `/api/v1/trends` | no | 60 / 60 s |
| `conversations.mute` | `POST` | `/api/v1/conversations/{id}/mute` | yes | 30 / 900 s |
| `conversations.unmute` | `DELETE` | `/api/v1/conversations/{id}/mute` | yes | 60 / 900 s |
| `inbox` | `POST` | `/inbox` | signature | 120 / 60 s |
| `inbox.shared` | `POST` | `/inbox/shared` | signature | 120 / 60 s |
| `actors.get` | `GET` | `/@{handle}` | no | 120 / 60 s |
| `actors.followers` | `GET` | `/@{handle}/followers` | no | 120 / 60 s |
| `actors.following` | `GET` | `/@{handle}/following` | no | 120 / 60 s |
| `actors.outbox` | `GET` | `/@{handle}/outbox` | no | 120 / 60 s |
| `webfinger` | `GET` | `/.well-known/webfinger` | no | 120 / 60 s |
| `mastodon.instance` | `GET` | `/api/mastodon/v1/instance` | no | 120 / 60 s |
| `mastodon.media.upload` | `POST` | `/api/mastodon/v1/media` | yes, `write:media` | 20 / 900 s |
| `mastodon.media.update` | `PUT` | `/api/mastodon/v1/media/{id}` | yes, `write:media` | 30 / 900 s |
| `mastodon.media.delete` | `DELETE` | `/api/mastodon/v1/media/{id}` | yes, `write:media` | 30 / 900 s |
| `mastodon.timelines.public` | `GET` | `/api/mastodon/v1/timelines/public` | no | 60 / 60 s |
| `mastodon.statuses.show` | `GET` | `/api/mastodon/v1/statuses/{id}` | no | 120 / 60 s |
| `mastodon.statuses.context` | `GET` | `/api/mastodon/v1/statuses/{id}/context` | no | 60 / 60 s |
| `mastodon.polls.show` | `GET` | `/api/mastodon/v1/polls/{id}` | no | 120 / 60 s |
| `mastodon.polls.vote` | `POST` | `/api/mastodon/v1/polls/{id}/votes` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.statuses.create` | `POST` | `/api/mastodon/v1/statuses` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.statuses.update` | `PUT` | `/api/mastodon/v1/statuses/{id}` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.statuses.delete` | `DELETE` | `/api/mastodon/v1/statuses/{id}` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.scheduled_statuses.list` | `GET` | `/api/mastodon/v1/scheduled_statuses` | yes, `read:statuses` | 60 / 60 s |
| `mastodon.scheduled_statuses.show` | `GET` | `/api/mastodon/v1/scheduled_statuses/{id}` | yes, `read:statuses` | 120 / 60 s |
| `mastodon.scheduled_statuses.update` | `PUT` | `/api/mastodon/v1/scheduled_statuses/{id}` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.scheduled_statuses.delete` | `DELETE` | `/api/mastodon/v1/scheduled_statuses/{id}` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.filters.list` | `GET` | `/api/mastodon/v1/filters` | yes, `read:filters` | 60 / 60 s |
| `mastodon.filters.create` | `POST` | `/api/mastodon/v1/filters` | yes, `write:filters` | 30 / 900 s |
| `mastodon.filters.show` | `GET` | `/api/mastodon/v1/filters/{id}` | yes, `read:filters` | 120 / 60 s |
| `mastodon.filters.update` | `PUT` | `/api/mastodon/v1/filters/{id}` | yes, `write:filters` | 30 / 900 s |
| `mastodon.filters.delete` | `DELETE` | `/api/mastodon/v1/filters/{id}` | yes, `write:filters` | 30 / 900 s |
| `mastodon.statuses.reblog` | `POST` | `/api/mastodon/v1/statuses/{id}/reblog` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.statuses.unreblog` | `POST` | `/api/mastodon/v1/statuses/{id}/unreblog` | yes, `write:statuses` | 30 / 900 s |
| `mastodon.statuses.favourite` | `POST` | `/api/mastodon/v1/statuses/{id}/favourite` | yes, `write:favourites` | 30 / 900 s |
| `mastodon.statuses.unfavourite` | `POST` | `/api/mastodon/v1/statuses/{id}/unfavourite` | yes, `write:favourites` | 30 / 900 s |
| `mastodon.statuses.bookmark` | `POST` | `/api/mastodon/v1/statuses/{id}/bookmark` | yes, `write:bookmarks` | 30 / 900 s |
| `mastodon.statuses.unbookmark` | `POST` | `/api/mastodon/v1/statuses/{id}/unbookmark` | yes, `write:bookmarks` | 30 / 900 s |
| `mastodon.bookmarks` | `GET` | `/api/mastodon/v1/bookmarks` | yes, `read:bookmarks` | 60 / 60 s |
| `mastodon.accounts.relationships` | `GET` | `/api/mastodon/v1/accounts/relationships` | yes, `read:follows` | 60 / 60 s |
| `mastodon.accounts.follow` | `POST` | `/api/mastodon/v1/accounts/{id}/follow` | yes, `write:follows` | 30 / 900 s |
| `mastodon.accounts.unfollow` | `POST` | `/api/mastodon/v1/accounts/{id}/unfollow` | yes, `write:follows` | 30 / 900 s |
| `mastodon.accounts.mute` | `POST` | `/api/mastodon/v1/accounts/{id}/mute` | yes, `write:mutes` | 30 / 900 s |
| `mastodon.accounts.unmute` | `POST` | `/api/mastodon/v1/accounts/{id}/unmute` | yes, `write:mutes` | 30 / 900 s |
| `mastodon.mutes` | `GET` | `/api/mastodon/v1/mutes` | yes, `read:mutes` | 60 / 60 s |
| `mastodon.lists.list` | `GET` | `/api/mastodon/v1/lists` | yes, `read:lists` | 60 / 60 s |
| `mastodon.lists.create` | `POST` | `/api/mastodon/v1/lists` | yes, `write:lists` | 30 / 900 s |
| `mastodon.lists.show` | `GET` | `/api/mastodon/v1/lists/{id}` | yes, `read:lists` | 60 / 60 s |
| `mastodon.lists.update` | `PUT` | `/api/mastodon/v1/lists/{id}` | yes, `write:lists` | 30 / 900 s |
| `mastodon.lists.delete` | `DELETE` | `/api/mastodon/v1/lists/{id}` | yes, `write:lists` | 30 / 900 s |
| `mastodon.lists.accounts` | `GET` | `/api/mastodon/v1/lists/{id}/accounts` | yes, `read:lists` | 60 / 60 s |
| `mastodon.lists.accounts.add` | `POST` | `/api/mastodon/v1/lists/{id}/accounts` | yes, `write:lists` | 30 / 900 s |
| `mastodon.lists.accounts.remove` | `DELETE` | `/api/mastodon/v1/lists/{id}/accounts` | yes, `write:lists` | 30 / 900 s |
| `mastodon.timelines.list` | `GET` | `/api/mastodon/v1/timelines/list/{list_id}` | yes, `read:lists` | 60 / 60 s |
| `mastodon.accounts.statuses` | `GET` | `/api/mastodon/v1/accounts/{id}/statuses` | no; an optional token adds viewer-scoped rows | 60 / 60 s |
| `mastodon.accounts.show` | `GET` | `/api/mastodon/v1/accounts/{id}` | no | 120 / 60 s |
| `mastodon.accounts.followers` | `GET` | `/api/mastodon/v1/accounts/{id}/followers` | no | 60 / 60 s |
| `mastodon.accounts.following` | `GET` | `/api/mastodon/v1/accounts/{id}/following` | no | 60 / 60 s |
| `mastodon.accounts.verify_credentials` | `GET` | `/api/mastodon/v1/accounts/verify_credentials` | yes, `read:accounts` | 60 / 60 s |
| `mastodon.notifications.list` | `GET` | `/api/mastodon/v1/notifications` | yes, `read:notifications` | 60 / 60 s |
| `mastodon.notifications.unread_count` | `GET` | `/api/mastodon/v1/notifications/unread_count` | yes, `read:notifications` | 60 / 60 s |
| `mastodon.notifications.dismiss` | `POST` | `/api/mastodon/v1/notifications/{id}/dismiss` | yes, `write:notifications` | 30 / 900 s |
| `mastodon.notifications.clear` | `POST` | `/api/mastodon/v1/notifications/clear` | yes, `write:notifications` | 30 / 900 s |
| `mastodon.search` | `GET` | `/api/mastodon/v1/search` | yes, `read:search` | 60 / 60 s |

Auth and rate limits are **declared** here and enforced by the host. A host that
ignores the declaration is a host bug; the manifest is what both hosts are
checked against.

## Health

```http
GET /healthz
```

```json
{"status": "ok"}
```

Does not touch the database; a failing probe means the process is gone, not that
a dependency is slow.

## Instance

```http
GET /api/v1/instance
```

```json
{"instance": {
  "name": "Aloha Social",
  "description": "A small instance",
  "version": "0.1.0",
  "api": "v1",
  "federation": true,
  "email": "admin@social.example",
  "contact_account": "alice",
  "registrations": false,
  "approval_required": true,
  "invites_enabled": false,
  "languages": ["de", "en"],
  "thumbnail": "https://social.example/thumbnail.png",
  "stats": {"user_count": 2, "status_count": 5, "domain_count": 3}
}}
```

The metadata comes from the optional `social/instance.*` keys documented in
[configuration](configuration.md#keys); unset values use the
defaults shown there. `stats` uses the optional `LocalActorCount`, `PostCount`
and `RemoteDomainCount` capabilities on the existing directory and post
repositories. A missing capability reports `0`; there is no statistics table,
migration or cleanup job. The Mastodon compatibility prefix projects the same
description and stats with its own field names; see
[Mastodon-compatible API](../guides/mastodon-api.md#instance-metadata).

## Upload media

```http
POST /api/v1/media
Content-Type: multipart/form-data; boundary=…

file=@cat.png;type=image/png
alt_text=A cat on a keyboard
```

The only file field is `file`; `alt_text` is optional. JPEG, PNG and WebP are
accepted up to 5 MiB and 40 megapixels. The original is stored unchanged and a
bounded thumbnail is generated next to it. A successful upload answers `201`:

```json
{"attachment": {"id": "…", "media_type": "image", "mime_type": "image/png",
  "width": 1200, "height": 800, "byte_size": 18342,
  "alt_text": "A cat on a keyboard", "created_at": "2026-11-11T10:00:00Z",
  "url": "https://social.example/api/v1/media/…",
  "thumbnail_url": "https://social.example/api/v1/media/…?variant=thumbnail"}}
```

Errors: `400 invalid_multipart`, `413 payload_too_large`, `422
validation_failed`, `503 media_not_configured` or `503
media_storage_unavailable`. A rejected upload leaves neither a blob nor a
metadata row. See [media](../guides/media.md).

## Fetch media

```http
GET /api/v1/media/{id}
GET /api/v1/media/{id}?variant=thumbnail
```

`200` with the exact stored bytes and the stored MIME type. The route needs no
session for media a post publishes (`public` or `unlisted`), so a remote server
can fetch a URL referenced by a published note; the id is random. Those
responses are immutable and cacheable for a year.

The audience of the linking post decides everything else. Media of a
`followers` post is served to its author and accepted local followers, media of
a `direct` post to its author and local recipients; `Cache-Control: private,
no-store` keeps a restricted copy out of shared caches. Everybody else —
anonymous clients included — gets the same `404 not_found` as an unknown id, so
the answer never confirms that the attachment exists. An attachment no post
links yet stays readable (composer preview of a fresh upload), and after a post
is deleted only its author may read its media.

A missing id or blob is `404 not_found`; an unknown variant is `400
invalid_media_variant`; an instance without a configured `BlobStore` is `503
media_not_configured`. The rule lives in `Service\\MediaAccess`.

## Write a post

```http
POST /api/v1/posts
Content-Type: application/json

{"content": "hello", "visibility": "public", "content_warning": null,
 "attachment_ids": ["…"], "recipient_ids": ["bob"],
 "in_reply_to_id": "…"}
```

`attachment_ids` is optional and limited to four ids, each uploaded by the
author. The post and its attachment links are written in one transaction; an
id that does not belong to the caller is `422 validation_failed` and creates
no post. `recipient_ids` is a list of known local accounts and is only accepted
for `direct`; `in_reply_to_id` must name a live parent the author can read. A
reply cannot be more public than its parent; a direct reply inherits the
parent participants. Direct posts stay local and queue no remote delivery;
other posts queue delivery after the write.

`201` with a `Location` header:

```json
{"post": {"id": "…", "author_id": "alice", "content": "hello",
  "content_warning": null, "visibility": "public",
  "published_at": "2026-11-11T10:00:00Z", "deleted_at": null,
  "in_reply_to_id": null, "recipient_ids": [],
  "attachments": [{"id": "…", "media_type": "image", "mime_type": "image/png",
    "width": 1200, "height": 800, "byte_size": 18342,
    "alt_text": "A cat on a keyboard", "created_at": "2026-11-11T10:00:00Z",
    "url": "https://social.example/api/v1/media/…",
    "thumbnail_url": "https://social.example/api/v1/media/…?variant=thumbnail"}]}}
```

`attachments` is omitted for a post without attachments, so existing shapes do
not change. Errors: `400 invalid_json`, `401 authentication_required`,
`422 validation_failed` with `error.fields`.

## Publish to AT Protocol

```http
POST /api/v1/posts/{id}/atproto
Authorization: Bearer …
```

Mirrors one public, attachment-free local text post to the account's PDS with
`com.atproto.repo.putRecord`. The request has no body; the post is identified
by the path. `200` with the stored publication:

```json
{"publication": {"post_id": "…", "rkey": "3mabcdefghijk",
  "uri": "at://did:plc:alice/app.bsky.feed.post/3mabcdefghijk",
  "published_at": "2026-11-11T10:00:00Z"}}
```

A repeat returns the same row and makes no second PDS request. Errors:
`401 authentication_required`; `404 not_found` for a missing/deleted post or
another account's post; `409 atproto_account_not_connected`;
`422 atproto_post_unsupported` with `error.fields.reason` equal to
`not_public`, `attachments_unsupported` or `text_too_long`;
`502 atproto_publish_failed`; `503 atproto_publish_disabled` when
`social/atproto.publish` is off; `503 atproto_not_configured` when no
resolver/publisher is assembled. No refusal writes a publication row. See
[AT Protocol publishing](../guides/atproto.md).

`GET /api/v1/posts/{id}/atproto` reads the stored state of the caller's own
post. It returns the local idempotency row, not a PDS response:

```json
{"publication": null}
```

before the first publication, and the object shown above after it. The route
stays available when publishing is disabled, so a client can still display an
existing mirror. A missing, deleted or foreign post is `404 not_found`; an
anonymous request is `401 authentication_required`.

## Report a post, actor or domain

POST /api/v1/reports

```json
{
  "target_type": "post",
  "target_id": "post-id",
  "reason": "spam",
  "comment": "Repeated unsolicited links",
  "evidence": ["https://remote.example/notes/1"]
}
```

The target types are `post`, `actor` and `domain`; reasons are `spam`,
`harassment`, `hate`, `csam`, `violence`, `misinformation` and `other`. The
target must be visible or known to the reporter, a post owned by the reporter is
refused, and at most 20 evidence entries of 500 characters each are stored. An
offer of the same target, reason and reporter while the first report is open
returns the existing report instead of opening a duplicate.

A report is created with status `pending` and never changes the reported post
by itself. Review is not an HTTP route: a host passes its own
`Platform\Moderation\AdminAuthorization` to the application and calls
`ModerationService::queue()`, `resolve()` or `reject()`. Resolving applies one
of `suspend_actor`, `silence_actor`, `block_domain` or `no_action`;
`suspend_actor`, `silence_actor` and `block_domain` create an auditable action
that can be reversed by its id. `silence_actor` hides local posts from public
and tag timelines, while `suspend_actor` additionally refuses inbox activities
from the actor and outbound delivery to it. A domain block covers the exact
host; subdomains are not implied.

Inbound ActivityPub `Flag` activities are accepted over the signed inbox and
become reports with reason `other`, the flagging actor as reporter and a
bounded excerpt of the activity content as evidence. A replayed activity id is
acknowledged without creating a second report. A blocked reporter is refused
with `403 moderation_blocked`.

## Read a post

```http
GET /api/v1/posts/{id}
```

`200` with `{"post": …}`, including the same `attachments` array as the
write response. A missing, deleted or non-public post answers `404`, except
that a followers-only post is also readable by an accepted local follower; see
[posts and timelines](../guides/posts-and-timelines.md).

## Edit a post

```http
PATCH /api/v1/posts/{id}
```

A patch: an absent field is left alone, `content_warning: null` clears the
warning. `content`, `content_warning`, `attachment_ids` and `visibility` are
accepted; send `revision` (the value a read returned) to get `409
post_revision_conflict` instead of overwriting a concurrent edit. `200` with
the updated `{"post": …}`, its new `revision` and a non-null `edited_at`.
Errors: `401 authentication_required`, `403 forbidden` for somebody else's
post, `404 not_found` for a missing or deleted one, `422 validation_failed`
with `error.fields` when the new text, warning, attachment ids or visibility
are refused. An edit can narrow an audience but never widen it, and a direct
post keeps its recipients. The replaced version is kept in
`social_post_revision`; every read sees the current one, and a non-direct post
with remote followers gets an ActivityPub `Update` carrying exactly the note a
`Create` would. See [posts and timelines](../guides/posts-and-timelines.md).

## Delete a post

```http
DELETE /api/v1/posts/{id}
```

`204` on success, `403 forbidden` when the post belongs to somebody else, `404`
when it does not exist.

## Remote reactions

```http
GET    /api/v1/remote-posts/{id}
POST   /api/v1/remote-posts/{id}/like
DELETE /api/v1/remote-posts/{id}/like
POST   /api/v1/remote-posts/{id}/reactions        {"emoji": "❤"}
DELETE /api/v1/remote-posts/{id}/reactions/{emoji}
```

`{id}` is the stored remote object id, URL-encoded as one path segment. The
post must be a live row from an actor the caller follows; otherwise the answer
is `404 remote_post_not_found`. The local reaction is stored before the signed
`Like`, `Undo(Like)`, `EmojiReact` or `Undo(EmojiReact)` is queued, so the read
model changes even when delivery is retried. A repeated call is idempotent and
queues no second activity. `EmojiReact` accepts Unicode emoji only; a custom
emoji shortcode is refused because the core has no verified local emoji source.

```json
{"interaction": {
  "favourites_count": 1,
  "emoji_reactions": [{"emoji": "❤", "count": 1}],
  "viewer": {"favourited": true, "reactions": ["❤"]}
}}
```

The remote projection is the caller's own state, not a remote total: the core
does not fetch a remote count on a read. Inbound reactions on local posts are
counted in the neutral post detail (`post.interactions`) and in the Mastodon
`favourites_count`; emoji reactions stay a separate list.

## Remote thread/context

```http
GET /api/v1/remote-posts/{id}/thread
```

`{id}` is the remote **activity id**, URL-encoded as one path segment, for
 example `https%3A%2F%2Fremote.example%2Factivities%2Fcreate-1`. The target row
must be a live `social_remote_post` from an actor the caller follows and does
not block; otherwise the answer is `404 not_found`, identical to a missing row.

```json
{"thread": {
  "post": {"object_id": "https://remote.example/notes/target",
    "author_id": "https://remote.example/users/bob", "content": "target",
    "content_warning": null, "published_at": "2026-11-11T10:02:00Z",
    "in_reply_to_id": "https://remote.example/notes/parent"},
  "ancestors": [ … ],
  "replies": [ … ]
}}
```

`ancestors` is ordered root first, directly before the target, up to
`thread.max_depth` (default 5). `replies` contains only direct children whose
`in_reply_to_id` is the target object, oldest first, up to
`thread.max_replies` (default 20); it does not walk reply chains. Both arrays
omit nodes whose author the caller blocks. `post` is always the freshly
fetched target; `fetched_at` is cached by the service but not exposed in this
response.

Errors: `401 authentication_required`; `404 not_found` for a missing, deleted,
unfollowed or blocked target; `503 thread_fetch_disabled` when
`social/thread.fetch` is off; `503 thread_not_configured` when no bounded HTTP
client/service is assembled; `502 thread_fetch_failed` when the target itself
cannot be fetched or parsed. Ancestor or reply failures do **not** fail the
request: they are logged with a stable `thread_*` reason and omitted. See
[remote threads](../guides/remote-threads.md).

## Timelines

```http
GET /api/v1/timelines/public?limit=20&before=2026-11-11T10:00:00Z
GET /api/v1/timelines/home
```

```json
{"posts": [ … ]}
```

`limit` defaults to 20 and is clamped to 1–40. `before` is exclusive. A cursor
that is not a canonical timestamp is `400 invalid_cursor`. `GET
/api/v1/timelines/tag/{tag}` uses the same limit and cursor rules, normalizes
the tag by stripping one leading `#` and lowercasing it, and returns
`{"tag": "devops", "posts": […]}`. Tags are extracted from post content when
the post is stored; matching is exact, so `#devops` does not match `#devops_extra`.
Only `public` posts appear; unlisted posts are readable by link but never
listed. A tag that is empty or contains anything
other than letters, digits or underscores is `400 invalid_tag`. The public timeline
contains local posts; the home timeline contains the caller's own local posts,
remote posts and boosts from followed remote actors, and live local posts from
followed local accounts, with `kind`, `object_id`, `object_author_id` and
`boosted_by` on each entry. The private followed-hashtag page is described
below. A blocked followed local account is omitted until
it is unblocked. `direct` posts are not part of the local half. Local posts carry the same
`attachments` array as the single-post response. A remote entry carries the
same local-original/thumbnail shape for successfully imported federated
images, and omits the key when it has none. See
[posts and timelines](../guides/posts-and-timelines.md) and
[media](../guides/media.md#remote-attachments-from-incoming-posts).

### Followed hashtags

```http
GET /api/v1/followed_tags?limit=20&before=2026-11-11T10:00:00Z&before_tag=devops
POST /api/v1/followed_tags
Content-Type: application/json

{"tag": "#DevOps"}

DELETE /api/v1/followed_tags/devops
GET /api/v1/timelines/followed_tags?limit=20&before=2026-11-11T10:00:00Z
```

Follows are private to the signed-in account and persist in
`social_followed_hashtag`. `POST` normalizes the tag (one optional leading
`#`, lower case, letters/digits/underscores) and answers `201` for a new row
or `200` for an existing one with its original `created_at`; both carry
`{"tag": "devops", "created_at": "…"}`. `DELETE` is idempotent and answers
`{"tag": "devops"}`. `GET /api/v1/followed_tags` returns the caller's tags
newest first in `{"tags": [{"tag": "…", "created_at": "…"}]}`; `limit`
defaults to 20 and is clamped to 1–40, `before` is the exclusive `created_at`
and `before_tag` breaks an equal-timestamp tie. An account may follow at most
100 tags; the next new follow is `400 followed_tag_limit`.

`GET /api/v1/timelines/followed_tags` returns
`{"tags": ["devops", …], "posts": […]}` for every `public` post
carrying any followed tag. It uses the same `limit` (1–40) and exclusive
`before` timestamp cursor as the public tag timeline, and a post carrying two
followed tags appears once. Followers-only, direct and deleted posts never
appear. With no followed tags the page is empty rather than an error. A
malformed `before` is `400 invalid_cursor`; a malformed tag or `before_tag` is
`400 invalid_tag`.

## Followers

```http
POST /api/v1/followers
Content-Type: application/json

{"actor": "https://remote.example/users/bob",
 "inbox": "https://remote.example/users/bob/inbox",
 "shared_inbox": "https://remote.example/inbox/shared"}
```

`shared_inbox` is optional and may be omitted or `null`; when present it must
be an absolute http(s) URL of at most 512 characters. It is stored for batched
delivery but is not echoed in the response. `201` with
`Location: /api/v1/followers/{id}` when the follower is new, `200` when the
actor was already a follower and only the individual or shared inbox changed:

```json
{"follower": {"id": "…", "actor": "https://remote.example/users/bob",
  "inbox": "https://remote.example/users/bob/inbox",
  "followed_at": "2026-11-11T10:00:00Z"}}
```

```http
GET /api/v1/followers?limit=20&before=2026-11-11T10:00:00Z
```

```json
{"followers": [ … ], "total": 1}
```

```http
DELETE /api/v1/followers/{id}
```

`204`, or `404` when the follower does not exist **or belongs to another
account** — the answer must not reveal the difference.

## Following

```http
POST /api/v1/following
Content-Type: application/json

{"actor": "https://remote.example/users/bob",
 "inbox": "https://remote.example/users/bob/inbox"}
```

`201` with `Location: /api/v1/following/{id}` when the outgoing edge is new,
`200` when the actor was already followed or still pending:

```json
{"following": {"id": "…", "actor": "https://remote.example/users/bob",
  "inbox": "https://remote.example/users/bob/inbox",
  "followed_at": "2026-11-11T10:00:00Z", "state": "pending"}}
```

The endpoint stores a `pending` edge and queues a `federation.follow` job. The
worker delivers the signed ActivityPub `Follow`; a failed delivery stays
queued and is retried, and a repeated `POST` queues nothing. A signed
`Accept`/`Reject` from the target actor moves the row to `accepted`/`rejected`;
the public following collection and home timeline read accepted rows only.

```http
DELETE /api/v1/following/{id}
```

`204` after the edge was removed and the `Undo` of the embedded `Follow` was
queued. The worker retries a failed `Undo`; the edge stays gone, and an
`Accept`/`Reject` arriving later is a no-op. An id of another account is `404
not_found`; a write without an HTTP client or signing key is `503
federation_not_configured`. See the
[following collection guide](../guides/following-collection.md).

## Blocks

```http
POST /api/v1/blocks
Content-Type: application/json

{"actor": "https://remote.example/users/bob"}
```

`201` with `Location: /api/v1/blocks/{id}` when the actor is newly blocked,
`200` with the existing block when the caller already blocked it:

```json
{"block": {"id": "…", "actor": "https://remote.example/users/bob",
  "blocked_at": "2026-11-11T10:00:00Z"}}
```

```http
GET /api/v1/blocks?limit=20&before=2026-11-11T10:00:00Z
```

```json
{"blocks": [ … ], "total": 1}
```

`limit` defaults to 20 and is clamped to 1–100. `before` is exclusive; a value
that is not a canonical timestamp is `400 invalid_cursor`. A block hides the
actor's remote posts and boosts, and a followed local actor's local posts,
from this caller's home timeline only; it does not remove the shared remote
rows, the local follow edge or another account's view. Delete it with
`DELETE /api/v1/blocks/{id}` (`204`, or `404` when it is not the caller's).
See [blocks](../guides/blocks.md).

## Local accounts

```http
GET /api/v1/accounts/{id}
```

A public local profile. When the request carries an authenticated local user,
`relationship` is that viewer's relationship; an anonymous request gets
`null` rather than a guessed viewer.

```json
{"account": {
  "id": "alice",
  "handle": "alice",
  "display_name": "alice",
  "url": "https://social.example/@alice",
  "followers_count": 3,
  "following_count": 5,
  "relationship": {
    "following": false,
    "followed_by": true,
    "blocking": false,
    "muting": false
  }
}}
```

An unknown, empty or non-local id is `404 not_found`. A host that did not
supply a `LocalActorDirectory` answers `503 accounts_not_configured`.
`display_name` is the local handle in this slice because profile detail fields
remain host-owned; a host may render its own profile header around the shared
component.

```http
POST /api/v1/accounts/{id}/follow
DELETE /api/v1/accounts/{id}/follow
```

Both return the resulting relationship and are idempotent: repeating a follow
creates no second edge, and unfollowing an absent edge is a successful no-op.

```json
{"relationship": {"following": true, "followed_by": false,
  "blocking": false, "muting": false}}
```

A self-follow is `422 validation_failed` with an `id` field; an unknown target
is `404`; a write without a local directory is `503`; an anonymous write is
`401`. Local follows stay in the existing follower graph and are not delivered
to a remote inbox. See [federation](../guides/federation.md).

## Notifications

```http
GET /api/v1/notifications?limit=20&before=notification-…
```

One page of the caller's unread local events, newest first, plus the current
unread count.

```json
{"notifications": [{
  "id": "notification-…",
  "type": "mention",
  "actor_id": "alice",
  "post_id": "post-…",
  "created_at": "2026-11-13T10:00:00Z",
  "read": false
}], "unread_count": 1}
```

`type` is `favourite`, `follow`, `mention` or `reblog`. `actor_id` is a local
account id or, for a remote follower, its actor URL. `post_id` is `null` for a
follow and the reply/post id for the other types. `limit` defaults to 20 and is
clamped to 1–40. `before` is a notification id owned by the caller; an unknown
or foreign id is `422 validation_failed` with a `before` field, so the cursor
cannot probe another account's events. An unavailable notification table is
`503 notifications_unavailable`.

```http
GET /api/v1/notifications/unread_count
```

```json
{"count": 3}
```

```http
POST /api/v1/notifications/{id}/dismiss
POST /api/v1/notifications/clear
```

Dismiss marks one notification read and answers `{"dismissed": true}`. It is
idempotent for the caller's own already-read row; a missing or foreign id is
`404 not_found`. Clear marks every unread notification of the caller read and
answers `{"cleared": 3}`; repeating it returns `0`. Read rows stay stored and
become eligible for the cleanup job after the configured retention;
`GET /api/v1/notifications` returns unread visible rows only. The core records a local
follow, favourite, mention/reply and reblog; recording is best-effort and never
fails the primary interaction.

## Notification policy and requests

```http
GET|PATCH /api/v1/notifications/policy

{
  "actions": {
    "mutual": "allow", "following": "allow", "follower": "allow",
    "new": "hold", "stranger": "hold"
  },
  "new_account_days": 7
}
```

The five categories are `mutual`, `following`, `follower`, `new` and
`stranger`. An action is `allow`, `hold` or `drop`. The core's protective
default holds `new` and `stranger`; existing installations keep the permissive
all-allow policy unless a host opts into the protective default. A patch changes
only the categories it names, and existing stored events keep the status they
were recorded with. `new_account_days` accepts 0–3650. An invalid category or
action is `422 validation_failed`.

```http
GET  /api/v1/notifications/requests
POST /api/v1/notifications/requests/{id}/accept
POST /api/v1/notifications/requests/{id}/dismiss
DELETE /api/v1/notifications/requests/{id}
```

A `hold` stores the event as `held`, creates one sender request and leaves it out
of the notification list and unread count. Accepting releases every held event
from that sender as visible without re-invoking the downstream event sink;
dismissing keeps the events for audit and drops them from the visible list;
revoking removes the sender decision so the category policy applies again. These
transitions never change the follow, favourite, boost or post that caused the
event. Request ids are scoped to the caller, so a foreign id is `404`.

```http
POST   /api/v1/conversations/{id}/mute
DELETE /api/v1/conversations/{id}/mute
```

A conversation mute is keyed by the resolved thread root, local or remote. It
suppresses alerts for that thread only; the posts stay visible. An unknown root
is stored against the submitted id rather than guessed into another thread. A
missing mute on delete is a successful no-op. The notification list and unread
count apply the visible status before pagination, filters and Mastodon
notification projections; held and dropped rows stay available to the policy
audit and cleanup.

## Search and trends

```http
GET /api/v1/search?q=hello&type=statuses&limit=20&cursor=…
GET /api/v1/trends?limit=20&window=604800
```

Search reads the bounded discovery index, never the post or actor tables.
`type` is `accounts`, `statuses` or `hashtags`; an omitted type returns accounts
and statuses together. Only public records are indexed. A handle query may make
one WebFinger and one actor request through the SSRF-guarded federation
fetcher; the remote profile is then cached in the index. A remote failure is
reported as `partial: true` with a stable `remote_error`, while local and
cached results stay available. The opaque cursor is bound to the query and
rejected with `400 invalid_cursor` when it is stale or belongs to another
query.

`trends` aggregates `social_search_trend` rows over `window` seconds, capped at
30 days, and returns `{hashtag, count}` entries. It never scans post content.
The Mastodon `GET /api/v1/search?type=hashtags` projection uses the same
records; its `history` array stays empty because the core does not claim
per-day account counts it does not store.

## Inbox

```http
POST /inbox
Signature: keyId="…",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="…"

{"id":"https://remote.example/activities/follow-1","type":"Follow",
 "actor":"https://remote.example/users/bob","object":"https://social.example/@alice"}
```

`202 {"status": "accepted"}`. The signature is the authentication. Supported
activities and every refusal are listed in
[inbox activities](inbox-activities.md). `503 inbox_not_configured` means the
host did not provide an actor resolver, an HTTP client and a signing key.

## Shared inbox

```http
POST /inbox/shared
Signature: keyId="…",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="…"

{"id":"https://remote.example/activities/create-1","type":"Create",
 "actor":"https://remote.example/users/bob","object":{"id":"…","content":"…"}}
```

`202 {"status": "accepted"}`. The route applies exactly the same signature,
actor, policy, replay and ownership checks as `POST /inbox`; it exists so a
peer can address one endpoint for every local actor. The signature must cover
`/inbox/shared` as its `(request-target)`. A signed `Follow` delivered here
still stores the remote actor's advertised shared inbox and answers on its
individual inbox. See [shared inboxes](../guides/shared-inboxes.md).

## Actor document

```http
GET /@alice
Accept: application/activity+json
```

`200` with the ActivityStreams document, `Content-Type:
application/activity+json; charset=utf-8`. Every field is described in
[the actor document reference](actor-document.md). An unknown handle is
`404 actor_not_found`; an instance without a key or a local directory is
`503 actor_not_configured`.

## Followers collection

```http
GET /@alice/followers
GET /@alice/followers?page=1&limit=20
```

The root is an `OrderedCollection` with `totalItems` and `first`; `page=1`
returns an `OrderedCollectionPage` with `partOf`, `orderedItems` and
`next`/`prev` where applicable. It reflects accepted Follows and Undos
immediately; see the
[followers collection guide](../guides/followers-collection.md).

## Following collection

```http
GET /@alice/following
```

`200` with a paged `OrderedCollection` whose `id` is exactly the `following`
URL of the actor document. Add `?page=1` for an `OrderedCollectionPage`. It
reads the outgoing follow repository; `POST` and `DELETE /api/v1/following`
write it, while `POST /api/v1/followers` and inbound Follows do not change it.
See the [following collection guide](../guides/following-collection.md). An
unknown handle is `404 actor_not_found`; an instance without a key or
directory is `503 actor_not_configured`.

## Outbox

```http
GET /@alice/outbox
```

`200` with a paged `OrderedCollection` whose `id` is exactly the `outbox` URL
of the [actor document](actor-document.md). Add `?page=1` for an
`OrderedCollectionPage`; items are the same `Create` activities outbound
delivery sends, newest first, and only `public` posts appear: unlisted stays
out of the enumerable collection. See
the [outbox guide](../guides/outbox.md). An unknown handle is `404
actor_not_found`; an instance without a key or a local directory is `503
actor_not_configured`.

## WebFinger

```http
GET /.well-known/webfinger?resource=acct:alice@social.example
Accept: application/jrd+json
```

`200` with the JRD, `Content-Type: application/jrd+json; charset=utf-8`. Its
single `self` link points at the URL the actor document is really served
under. See [WebFinger](../guides/webfinger.md). A missing or malformed
`resource` is `400 invalid_resource`; a foreign host or unknown handle is
`404 actor_not_found`; an instance without a key or directory is
`503 actor_not_configured`.

## Boost and unboost a post

```http
POST /api/v1/posts/{id}/boost
Authorization: Bearer …
```

Shares a visible public or unlisted post. `201` returns
`{"boost": {"id", "user_id", "post_id", "created_at"}}`; repeating the call
returns the existing boost with `200`. A followers-only or direct post is `422
validation_failed`, and a post the caller cannot see is `404 not_found`. The
local row is written before one `Announce` per follower inbox is queued, so a
queue outage does not undo the share; a `reblog` notification is recorded for
the author unless the booster is the author. `DELETE` removes only the caller's
boost, queues `Undo(Announce)`, and answers `204` even when no boost existed.

## Mastodon-compatible API

The forty-three routes above are additive and disabled by default. Their paths use
`social/mastodon.prefix` (default `/api/mastodon/v1`); the table shows the
default. With `social/mastodon.api` off, every one answers `404
mastodon_api_disabled` before auth and scope checks. They never shadow
`/api/v1`. See [the compatibility guide](../guides/mastodon-api.md).

```http
GET /api/mastodon/v1/instance
GET /api/mastodon/v1/timelines/public?limit=20&max_id=…
GET /api/mastodon/v1/statuses/{id}
GET /api/mastodon/v1/statuses/{id}/context
GET /api/mastodon/v1/polls/poll-{postId}
POST /api/mastodon/v1/polls/poll-{postId}/votes
Content-Type: application/x-www-form-urlencoded

choices[]=0
POST /api/mastodon/v1/statuses/{id}/favourite
POST /api/mastodon/v1/statuses/{id}/unfavourite
POST /api/mastodon/v1/statuses/{id}/bookmark
POST /api/mastodon/v1/statuses/{id}/unbookmark
GET /api/mastodon/v1/bookmarks?limit=20&max_id=…
POST /api/mastodon/v1/statuses/{id}/reblog
POST /api/mastodon/v1/statuses/{id}/unreblog
GET /api/mastodon/v1/accounts/relationships?id[]=bob&id[]=carol
POST /api/mastodon/v1/accounts/bob/follow
POST /api/mastodon/v1/accounts/bob/unfollow
POST /api/mastodon/v1/accounts/bob/mute
Content-Type: application/x-www-form-urlencoded

notifications=1&duration=86400
POST /api/mastodon/v1/accounts/bob/unmute
GET /api/mastodon/v1/mutes?limit=40&max_id=bob
GET /api/mastodon/v1/lists
POST /api/mastodon/v1/lists
Content-Type: application/x-www-form-urlencoded

title=Close%20friends&replies_policy=list&exclusive=true
GET /api/mastodon/v1/lists/list-abc
PUT /api/mastodon/v1/lists/list-abc
Content-Type: application/x-www-form-urlencoded

title=Family&replies_policy=none&exclusive=false
DELETE /api/mastodon/v1/lists/list-abc
GET /api/mastodon/v1/lists/list-abc/accounts?limit=40&max_id=carol
POST /api/mastodon/v1/lists/list-abc/accounts
Content-Type: application/x-www-form-urlencoded

account_ids[]=bob&account_ids[]=carol
DELETE /api/mastodon/v1/lists/list-abc/accounts
Content-Type: application/x-www-form-urlencoded

account_ids[]=bob
GET /api/mastodon/v1/timelines/list/list-abc?limit=20&max_id=status-id
GET /api/mastodon/v1/accounts/bob/statuses?limit=20
GET /api/mastodon/v1/accounts/bob
GET /api/mastodon/v1/accounts/bob/followers
GET /api/mastodon/v1/accounts/bob/following
GET /api/mastodon/v1/search?q=hello&type=statuses
GET /api/mastodon/v1/notifications?limit=20&types[]=favourite
GET /api/mastodon/v1/notifications/unread_count?limit=100
POST /api/mastodon/v1/notifications/{id}/dismiss
POST /api/mastodon/v1/notifications/clear
POST /api/mastodon/v1/statuses
Idempotency-Key: client-key-1
Content-Type: application/json

{"status": "hello", "visibility": "public",
 "poll": {"options": ["Yes", "No"], "expires_in": 3600}}
```

A status response is the usual Mastodon shape (`id`, `content`,
`spoiler_text`, `sensitive`, `visibility`, `account`, `media_attachments`,
`poll`, `favourites_count`, `favourited`, …). `poll` is `null` without one;
otherwise it carries two to four options, a clock-derived `expired` flag and
the stored vote counts; `voted`/`own_votes` are viewer-specific. `private` maps to
followers-only; `direct` uses `recipient_ids` or `@local-id` mentions, and
`in_reply_to_id` names the local parent. The write goes through `PostService`,
so validation, ownership, soft deletion and delivery are identical to
`/api/v1/posts`. A valid `poll` is
written in the same transaction as its post; invalid options or expiry are
`422 validation_failed` and leave no status. `Idempotency-Key` makes a retry
return the same status, including its poll, instead of inserting a duplicate.

`POST /api/mastodon/v1/statuses/{id}/favourite` and
`POST /api/mastodon/v1/statuses/{id}/unfavourite` require
`write:favourites`. They write only the caller's row in `social_favourite` and
return the projected status; a repeat favourite and an absent unfavourite are
both idempotent. The public count is visible to every reader, while
`favourited` is true only for the authenticated viewer. Missing, deleted or
invisible statuses answer `404 not_found`; an assembly without a
`FavouriteRepository` answers `503 favourites_not_configured`.

`POST /api/mastodon/v1/statuses/{id}/bookmark` and
`POST /api/mastodon/v1/statuses/{id}/unbookmark` require `write:bookmarks` and
write only the caller's row in `social_bookmark`. A bookmark is private: it
has no public count and creates no notification. `GET /api/mastodon/v1/bookmarks`
requires `read:bookmarks` and returns the caller's statuses newest-first with
`limit` (1–40), `max_id`, `since_id`/`min_id` and a `Link` header. The cursor
must be one of the caller's bookmarked status ids; an unknown or foreign id is
`404 not_found`, and combining `max_id` with a newer cursor is `422
validation_failed`. Deleted or newly invisible statuses are omitted. Repeating
a bookmark, or unbookmarking an absent one, is idempotent. An assembly without
`BookmarkRepository` answers `503 bookmarks_not_configured`.

`POST /api/mastodon/v1/accounts/{id}/mute` stores one private mute for a local
account id or remote actor URL and returns the relationship with
`muting: true`. `notifications` defaults to false and additionally filters the
target's notification events; `duration` is a positive seconds value, with
absent/`0` meaning indefinite. Both writes require `write:mutes` and are
idempotent through the `(user_id, actor_id)` key in `social_mute`; a self-mute
is `422` and an unknown bare target is `404`. `GET
/api/mastodon/v1/mutes` requires `read:mutes` and lists active mutes
newest-first with `limit` (1–80), `max_id`, `since_id`/`min_id` and a `Link`;
the cursor is an account id owned by the caller. Active mutes hide the target
from the home timeline and, with `notifications`, from the notification list
and unread count. Expired rows are ignored on read. An assembly without a
`MuteService` answers `503 mutes_not_configured`.

`GET /api/mastodon/v1/lists` returns the caller's private lists newest-first;
`POST` creates one with a required `title` (trimmed, 255 characters),
`replies_policy` (`followed` by default, `list` or `none`) and `exclusive`
(`false` by default). `GET`/`PUT`/`DELETE /lists/{id}` read, partially update
and delete an owned list; an id owned by another account is `404 not_found`.
Membership is written through `POST`/`DELETE /lists/{id}/accounts` with one to
100 `account_ids[]` values, all known local account ids; unknown ids refuse
the whole write, and repeats are idempotent. `GET /lists/{id}/accounts`
pages 1–80 local accounts with ownership-checked `max_id`/`since_id`/`min_id`
and the usual `Link`. `GET /timelines/list/{list_id}` merges member posts and
boosts with visibility, block and mute filtering. Its `replies_policy`
controls replies: `none` omits them, `list` keeps replies to members and
`followed` keeps replies to followed accounts or the viewer. An exclusive
list hides its members from the local-follow home timeline. An assembly
without `AccountListRepository` answers `503 lists_not_configured`.

`POST /api/mastodon/v1/statuses/{id}/reblog` returns the Mastodon reblog
wrapper: the outer status carries the boost id, time and booster account, and
the original status is nested under `reblog`. Repeating it returns the same
wrapper; `unreblog` removes only the caller's row. Both require
`write:statuses`, and only public/unlisted posts can be boosted. The same rows
provide `reblogs_count` and `reblogged`; `GET /statuses/{id}` accepts the boost
id as well. `GET /statuses/{id}/context` returns visible `ancestors` and
`descendants`, walking only rows the viewer may read. A reply or direct
recipient records a `mention` notification and a boost records a `reblog`
notification. `social_boost` from `BoostSchema` is the only new table; a host
without it must not expose these routes.

To upload an image first, send the same multipart envelope as
`/api/v1/media`:

```http
POST /api/mastodon/v1/media
Authorization: Bearer …
Content-Type: multipart/form-data; boundary=…

file=@cat.png;type=image/png
description=A cat on a keyboard
```

`200` with the Mastodon attachment shape (`id`, `type`, `url`, `preview_url`,
`meta`, `description`, …). JPEG, PNG and WebP are accepted up to 5 MiB and 40
megapixels; the `id` can then be used in `media_ids`. `focus` is ignored. An
optional `Idempotency-Key` makes a retry return the same attachment without a
second row or blob. Errors: `400 invalid_multipart`, `413 payload_too_large`,
`422 validation_failed` with `error.fields`, `503 media_not_configured` and
`503 media_storage_unavailable`; a rejected upload leaves no row or blob.

The metadata and lifecycle endpoints stay on the same attachment row and
blobs:

```http
PUT /api/mastodon/v1/media/{id}
Authorization: Bearer …
Content-Type: application/x-www-form-urlencoded

description=A cat on a keyboard&focus=0.0,0.0

DELETE /api/mastodon/v1/media/{id}
Authorization: Bearer …
```

`PUT` accepts JSON, form or multipart and returns `200` with the updated
attachment; an omitted `description` leaves the value unchanged and an empty
one clears it. `file` and `thumbnail` are refused with `422
unsupported_parameter`. Only an owned, unattached attachment can be changed or
deleted; a missing/foreign/attached id is `404 not_found` for `PUT`, while
`DELETE` answers `200 {}` for success and `422 validation_failed` when a status
still references the media. `DELETE` removes the row and both blobs. Both
routes require `write:media` and declare a `30/900` rate limit; no new table or
migration is involved.

`GET /api/mastodon/v1/notifications` returns the signed-in account's unread
local favourite, follow, mention and reblog events, newest first. The `account`
is the local actor id or, for a remote follower, the actor URL projected with a
minimal profile. A favourite, mention and reblog notification carries the
status; a follow notification and a favourite whose post no longer exists omit
`status`. Every returned row
has `"unread": true`. Repeat events use a deterministic id and cannot
duplicate. `types[]`/`exclude_types[]` filter known local types before the
page limit. Pagination accepts `limit` (1–40), `max_id`, `since_id` and
`min_id`; a cursor must belong to the caller. A non-empty page carries a
`Link` with `rel="next"`. `max_id` cannot be combined with
`since_id`/`min_id`, and an unknown cursor is `404 not_found`.

`POST /api/mastodon/v1/notifications/{id}/dismiss` sets `read_at` on one owned
notification and answers `200 {}`. It is idempotent for the caller's own id;
a missing or foreign id is `404 not_found`. `POST
/api/mastodon/v1/notifications/clear` sets `read_at` on every unread row of
the caller and answers `200 {}`; repeating it is also `200 {}`. New events
recorded after a clear are unread. `GET
/api/mastodon/v1/notifications/unread_count` returns `{"count": n}` with a
default cap of 100 and `limit` accepted up to 1000; the list filters,
including `account_id`, apply. The notification routes use the durable
`read_at` column and unread index on `social_notification`; no second table
is needed.
The routes declare scopes in the manifest and OpenAPI (`x-required-scopes`);
the host maps tokens to `Request::$scopes` and enforces the declared rate
limits. Errors: `404 mastodon_api_disabled`, `403 insufficient_scope`, `422
validation_failed`, `422 unsupported_parameter`, `404 not_found`, `503
favourites_not_configured`, `503 bookmarks_not_configured`, `503
mutes_not_configured`, `503 notifications_not_configured`, `503
notifications_unavailable` (missing or unreadable notification table, or a
table without `read_at`).

## Cleanup job (no route)

The retention cleanup from `0.6.0` is a worker job (`cleanup`), not an HTTP
route. It has no path, method, auth rule or rate limit, so it adds neither a
manifest route nor an OpenAPI path. The payload and
report are documented in
[retention cleanup](../guides/retention-cleanup.md); the stable reasons are
listed in the [error code reference](error-codes.md#cleanup-job-reasons). The
service deletes only unreferenced media, read notifications and thread-cache
rows, with the same strict `before` cutoff and deterministic oldest-first
limit on every run. A host schedules it through its existing worker or calls
`CleanupService::run()` directly.

## Transactions and job errors (no route)

Version `0.7.0` adds no HTTP route and no OpenAPI path. Post creation/deletion
is one transaction when the host supplies a `Platform\Db\TransactionRunner`,
and terminal worker errors are dead-lettered immediately. The worker codes
(`invalid_job_type`, `invalid_job_payload`, `unknown_job_type`,
`job_handler_failed`, `job_completion_failed`) are log/operator codes, never
response bodies; see [jobs and delivery](../guides/jobs-and-delivery.md) and
the [error code reference](error-codes.md#worker-and-queue-failure-codes). It
adds no HTTP route and no OpenAPI path either.

## Route parameters

A parameter matches one path segment (`[^/]+`); a slash in a value must be
percent-encoded. The router decodes parameters after matching, so an encoded
value reaches the handler intact without letting it swallow a second segment.
