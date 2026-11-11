# Routes

The manifest in [`src/Contract/Routes.php`](../../src/Contract/Routes.php) is the
source of truth; `php bin/export-routes` prints it as JSON. Paths are absolute
and versioned under `/api/v1` except the liveness probe.

| Name | Method | Path | Auth | Rate limit |
| --- | --- | --- | --- | --- |
| `health` | `GET` | `/healthz` | no | — |
| `instance.get` | `GET` | `/api/v1/instance` | no | — |
| `posts.create` | `POST` | `/api/v1/posts` | yes | 30 / 900 s |
| `posts.get` | `GET` | `/api/v1/posts/{id}` | no | — |
| `posts.delete` | `DELETE` | `/api/v1/posts/{id}` | yes | — |
| `timelines.public` | `GET` | `/api/v1/timelines/public` | no | — |
| `timelines.home` | `GET` | `/api/v1/timelines/home` | yes | — |
| `followers.list` | `GET` | `/api/v1/followers` | yes | 120 / 60 s |
| `followers.follow` | `POST` | `/api/v1/followers` | yes | 30 / 900 s |
| `followers.unfollow` | `DELETE` | `/api/v1/followers/{id}` | yes | 60 / 900 s |
| `inbox` | `POST` | `/inbox` | signature | 120 / 60 s |
| `actors.get` | `GET` | `/@{handle}` | no | 120 / 60 s |
| `actors.followers` | `GET` | `/@{handle}/followers` | no | 120 / 60 s |

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

## Write a post

```http
POST /api/v1/posts
Content-Type: application/json

{"content": "hello", "visibility": "public", "content_warning": null}
```

`201` with a `Location` header:

```json
{"post": {"id": "…", "author_id": "alice", "content": "hello",
  "content_warning": null, "visibility": "public",
  "published_at": "2026-11-11T10:00:00Z", "deleted_at": null}}
```

Errors: `400 invalid_json`, `401 authentication_required`,
`422 validation_failed` with `error.fields`.

## Read a post

```http
GET /api/v1/posts/{id}
```

`200` with `{"post": …}`. A missing, deleted or non-public post answers `404`;
see [posts and timelines](../guides/posts-and-timelines.md).

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
that is not a canonical timestamp is `400 invalid_cursor`.

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
```

`200` with an `OrderedCollection` (`totalItems`, `orderedItems`). It reflects
accepted Follows and Undos immediately; see the
[followers collection guide](../guides/followers-collection.md).

## Route parameters

A parameter matches one path segment (`[^/]+`); a slash in a value must be
percent-encoded. The router decodes parameters after matching, so an encoded
value reaches the handler intact without letting it swallow a second segment.
