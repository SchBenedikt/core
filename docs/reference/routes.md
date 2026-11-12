# Routes

The manifest in [`src/Contract/Routes.php`](../../src/Contract/Routes.php) is the
source of truth; `php bin/export-routes` prints it as JSON and
`php bin/export-openapi` renders the same routes as OpenAPI 3.1. Paths are absolute
and versioned under `/api/v1` except the liveness probe and the protocol
endpoints (`/inbox`, `/@{handle}`, `/.well-known/webfinger`).

| Name | Method | Path | Auth | Rate limit |
| --- | --- | --- | --- | --- |
| `health` | `GET` | `/healthz` | no | — |
| `instance.get` | `GET` | `/api/v1/instance` | no | — |
| `media.upload` | `POST` | `/api/v1/media` | yes | 20 / 900 s |
| `media.get` | `GET` | `/api/v1/media/{id}` | no | 240 / 60 s |
| `posts.create` | `POST` | `/api/v1/posts` | yes | 30 / 900 s |
| `posts.get` | `GET` | `/api/v1/posts/{id}` | no | — |
| `posts.delete` | `DELETE` | `/api/v1/posts/{id}` | yes | — |
| `timelines.public` | `GET` | `/api/v1/timelines/public` | no | — |
| `timelines.home` | `GET` | `/api/v1/timelines/home` | yes | — |
| `followers.list` | `GET` | `/api/v1/followers` | yes | 120 / 60 s |
| `followers.follow` | `POST` | `/api/v1/followers` | yes | 30 / 900 s |
| `followers.unfollow` | `DELETE` | `/api/v1/followers/{id}` | yes | 60 / 900 s |
| `following.follow` | `POST` | `/api/v1/following` | yes | 30 / 900 s |
| `following.unfollow` | `DELETE` | `/api/v1/following/{id}` | yes | 60 / 900 s |
| `blocks.list` | `GET` | `/api/v1/blocks` | yes | 120 / 60 s |
| `blocks.block` | `POST` | `/api/v1/blocks` | yes | 30 / 900 s |
| `blocks.unblock` | `DELETE` | `/api/v1/blocks/{id}` | yes | 60 / 900 s |
| `inbox` | `POST` | `/inbox` | signature | 120 / 60 s |
| `actors.get` | `GET` | `/@{handle}` | no | 120 / 60 s |
| `actors.followers` | `GET` | `/@{handle}/followers` | no | 120 / 60 s |
| `actors.following` | `GET` | `/@{handle}/following` | no | 120 / 60 s |
| `actors.outbox` | `GET` | `/@{handle}/outbox` | no | 120 / 60 s |
| `webfinger` | `GET` | `/.well-known/webfinger` | no | 120 / 60 s |

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
{"instance": {"name": "Aloha Social", "url": "https://social.example", "api": "v1"}}
```

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

`200` with the exact stored bytes and the stored MIME type. The route is
public so a remote server can fetch a URL referenced by a post; the id is
random. Responses are immutable and cacheable for a year. A missing id or blob
is `404 not_found`; an unknown variant is `400 invalid_media_variant`; an
instance without a configured `BlobStore` is `503 media_not_configured`.

## Write a post

```http
POST /api/v1/posts
Content-Type: application/json

{"content": "hello", "visibility": "public", "content_warning": null,
 "attachment_ids": ["…"]}
```

`attachment_ids` is optional and limited to four ids, each uploaded by the
author. The post and its attachment links are written in one transaction; an
id that does not belong to the caller is `422 validation_failed` and creates
no post. Delivery is queued after the write, as for any other post.

`201` with a `Location` header:

```json
{"post": {"id": "…", "author_id": "alice", "content": "hello",
  "content_warning": null, "visibility": "public",
  "published_at": "2026-11-11T10:00:00Z", "deleted_at": null,
  "attachments": [{"id": "…", "media_type": "image", "mime_type": "image/png",
    "width": 1200, "height": 800, "byte_size": 18342,
    "alt_text": "A cat on a keyboard", "created_at": "2026-11-11T10:00:00Z",
    "url": "https://social.example/api/v1/media/…",
    "thumbnail_url": "https://social.example/api/v1/media/…?variant=thumbnail"}]}}
```

`attachments` is omitted for a post without attachments, so existing shapes do
not change. Errors: `400 invalid_json`, `401 authentication_required`,
`422 validation_failed` with `error.fields`.

## Read a post

```http
GET /api/v1/posts/{id}
```

`200` with `{"post": …}`, including the same `attachments` array as the
write response. A missing, deleted or non-public post answers `404`; see
[posts and timelines](../guides/posts-and-timelines.md).

## Delete a post

```http
DELETE /api/v1/posts/{id}
```

`204` on success, `403 forbidden` when the post belongs to somebody else, `404`
when it does not exist.

## Timelines

```http
GET /api/v1/timelines/public?limit=20&before=2026-11-11T10:00:00Z
GET /api/v1/timelines/home
```

```json
{"posts": [ … ]}
```

`limit` defaults to 20 and is clamped to 1–40. `before` is exclusive. A cursor
that is not a canonical timestamp is `400 invalid_cursor`. The public timeline
contains local posts; the home timeline contains the caller's own local posts
plus remote posts and boosts from followed actors, with `kind`, `object_id`,
`object_author_id` and `boosted_by` on each entry. Local posts carry the same
`attachments` array as the single-post response; remote entries do not carry a
local attachment relation. See
[posts and timelines](../guides/posts-and-timelines.md).

## Followers

```http
POST /api/v1/followers
Content-Type: application/json

{"actor": "https://remote.example/users/bob",
 "inbox": "https://remote.example/users/bob/inbox"}
```

`201` with `Location: /api/v1/followers/{id}` when the follower is new, `200`
when the actor was already a follower and only the inbox changed:

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
`200` when the actor was already followed and only the inbox may have changed:

```json
{"following": {"id": "…", "actor": "https://remote.example/users/bob",
  "inbox": "https://remote.example/users/bob/inbox",
  "followed_at": "2026-11-11T10:00:00Z"}}
```

The endpoint stores the edge and delivers a signed ActivityPub `Follow` to the
inbox; a repeated `POST` sends nothing. If the peer refuses the delivery, the
edge is rolled back and the answer is `502 delivery_failed`.

```http
DELETE /api/v1/following/{id}
```

`204` after the peer accepted the `Undo` of the embedded `Follow`. A failed
`Undo` answers `502 delivery_failed` and keeps the edge, so the same request
can be retried. An id of another account is `404 not_found`; a write without
an HTTP client or signing key is `503 federation_not_configured`. See the
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
actor's remote posts and boosts from this caller's home timeline only; it does
not remove the shared remote rows or change another account's view. Delete it
with `DELETE /api/v1/blocks/{id}` (`204`, or `404` when it is not the caller's).
See [blocks](../guides/blocks.md).

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
delivery sends, newest first, and only public and unlisted posts appear. See
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

## Route parameters

A parameter matches one path segment (`[^/]+`); a slash in a value must be
percent-encoded. The router decodes parameters after matching, so an encoded
value reaches the handler intact without letting it swallow a second segment.
