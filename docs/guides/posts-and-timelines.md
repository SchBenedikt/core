# Posts and timelines

A local post is text on this instance. Federation is a side effect of writing
one, not a second kind of post. The home timeline also contains posts and
boosts received from remote actors that the reader follows.

## Writing

`POST /api/v1/posts` accepts:

| Field | Required | Rule |
| --- | --- | --- |
| `content` | yes | trimmed, 1–5000 characters after trimming |
| `content_warning` | no | trimmed; an empty string means "none"; at most 200 characters |
| `visibility` | no | `public` (default), `unlisted`, `followers`, `direct` |

Validation lives in `PostService::validate()` and returns one message per
field, which the API exposes as `error.fields`.

`direct` is refused with 422: a direct post needs recipients, and recipients
are part of a follow graph that only tracks remote followers so far. Refusing
is honest; sending an empty audience to the network is not.

## Visibility

| Visibility | Public timeline | Home timeline | Single post |
| --- | --- | --- | --- |
| `public` | yes | own posts; remote posts from followed actors | anyone |
| `unlisted` | yes | own posts; remote posts from followed actors | anyone |
| `followers` | no | own posts only | author only |
| `direct` | no | own posts only | author only |

The home timeline contains the reader's **own** local posts regardless of
visibility, plus remote posts and boosts by actors in the reader's outgoing
follow graph. Entries in the reader's private block list are excluded before
the page limit; both a blocked signing actor and a blocked boosted author are
filtered. See [blocks](blocks.md). It does not contain local public posts
from other accounts: local-to-local follow edges are not modelled yet. Remote
entries have no local visibility field and are serialized with
`visibility: null`.

The single-post rule is enforced by `PostService::getVisible()`: a non-public
post answers 404 to anyone but its author. `unlisted` is publicly readable by
design — it is only hidden from timelines.

## Timelines

`GET /api/v1/timelines/public` returns local `public` and `unlisted` posts,
newest first, with the id as the tie-break. Remote posts are not copied into
the public timeline.

`GET /api/v1/timelines/home` is the combined repository page: the caller's own
local posts plus live remote posts/boosts from followed remote actors, minus
actors the caller blocks. The two halves are filtered, sorted together by
published time and id, and only then limited, so a cursor cannot skip or
duplicate an entry at the boundary.

Home entries have a common shape:

```json
{"id": "https://remote.example/activities/announce-1",
 "author_id": "https://remote.example/users/bob",
 "object_id": "https://remote.example/notes/3",
 "object_author_id": "https://remote.example/users/bob",
 "content": "the boosted note",
 "content_warning": null,
 "visibility": null,
 "published_at": "2026-11-11T10:03:00Z",
 "kind": "boost",
 "boosted_by": "https://remote.example/users/bob"}
```

For a local post, `id` and `object_id` are the local post id,
`object_author_id` is the author, `kind` is `post`, and `boosted_by` is null.
For a remote post, `id` is the `Create` activity id. For a boost, `id` is the
`Announce` activity id, `object_id` is the boosted object, and `boosted_by` is
the announcing actor. A boost is one entry, not a second copy of the object.

Both timelines accept `limit` (default 20, clamped to 1–40) and `before`, an
exclusive canonical timestamp cursor. A cursor that is not a timestamp is a 400
(`invalid_cursor`) rather than "ignore and start over", because a client that
silently receives page one again would loop forever.

## Remote deletes

A remote `Delete` soft-deletes the stored post or boost by activity id or object
id. Every home-timeline query filters `deleted_at`, so the entry disappears on
the next page without deleting the row's idempotence key. If a peer deletes an
object that is not stored, the activity is still accepted as a no-op.

## Deleting a local post

`DELETE /api/v1/posts/{id}` soft-deletes: the row keeps `deleted_at`, every
local read filters it, and a `Delete` activity is queued for the audience.
Keeping the row means a retried `Delete` still has a stable object id to refer
to. There is no API route for fetching an individual remote post; remote
content is read through the home timeline.
