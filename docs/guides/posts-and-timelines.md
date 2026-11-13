# Posts and timelines

A local post is text on this instance. Federation is a side effect of writing
one, not a second kind of post. The home timeline also contains posts and
boosts received from remote actors that the reader follows, and local posts and
boosts from local accounts the reader follows.

## Writing

`POST /api/v1/posts` accepts:

| Field | Required | Rule |
| --- | --- | --- |
| `content` | yes | trimmed, 1–5000 characters after trimming |
| `content_warning` | no | trimmed; an empty string means "none"; at most 200 characters |
| `visibility` | no | `public` (default), `unlisted`, `followers`, `direct` |
| `recipient_ids` | no | list of known local account ids; only meaningful for `direct` |
| `in_reply_to_id` | no | id of a live local post the author may read |

Validation lives in `PostService::validate()` and returns one message per
field, which the API exposes as `error.fields`.

A `direct` post is local-only. Its `recipient_ids` name local accounts, not
remote inboxes; the author and those recipients can read it, and it is never
delivered to followers or remote actors. An empty recipient list, the author as
recipient, an unknown local id and a recipient on a non-direct post are `422`.

## Replies

`in_reply_to_id` stores the parent post id and is returned as the same field.
The parent must be live and readable by the author, otherwise the write is a
`422` rather than an existence oracle. A reply may not widen its parent's
audience: a direct parent requires a direct reply, and a followers-only parent
cannot receive a public or unlisted reply. A direct reply automatically
includes the parent author and the parent's other recipients, so the rest of
the conversation can see the answer. A reply records a `mention` notification
for its parent author (unless the author is replying to themselves), and a
direct post records one for each other recipient. Replies are local; the note
carries `inReplyTo` if it is ever read through the outbox or delivery path.

## Local boosts

`POST /api/v1/posts/{id}/boost` shares a visible public or unlisted post.
`DELETE /api/v1/posts/{id}/boost` removes only the caller's share and is an
idempotent no-op when absent. The same operation is `POST
{prefix}/statuses/{id}/reblog` and `POST {prefix}/statuses/{id}/unreblog`.
Followers-only and direct posts cannot be boosted: announcing them to the
booster's followers would create a new audience.

A local boost is one `social_boost` row, unique by `(user_id, post_id)`, so a
retry returns the existing boost. A new row queues one ActivityPub `Announce`
to the booster's followers through the normal delivery queue; removing it
queues `Undo(Announce)`. The local row is committed before the queue work, so
a queue outage never rolls the share back. A boost records a `reblog`
notification for the author (not for a self-boost), and the Mastodon projection
exposes the outer boost with the original status nested under `reblog`, plus
`reblogs_count` and `reblogged`. Deleting the post leaves the historical boost
for the host's cleanup policy; the post itself is no longer served.

## Visibility

| Visibility | Public timeline | Home timeline | Single post |
| --- | --- | --- | --- |
| `public` | yes | own posts and boosts; remote posts and boosts from followed actors; local posts and boosts from followed local accounts | anyone |
| `unlisted` | yes | own posts and boosts; remote posts and boosts from followed actors; local posts and boosts from followed local accounts | anyone |
| `followers` | no | own posts and boosts; local followers-only posts from followed local accounts | author and accepted local followers |
| `direct` | no | own posts and direct posts addressed to the reader | author and recipients |

The home timeline contains the reader's **own** local posts and boosts
regardless of visibility, remote posts and boosts by actors in the reader's
outgoing follow graph, and live local posts and boosts from local accounts the
reader follows through the local follow route. A local follow is an accepted follower edge, so those
local posts may include followers-only content; visibility is evaluated by
the post's own rules when it is read individually. A direct post appears in the
reader's own home timeline when the reader is its author or one of its
recipients. Entries in the reader's private block list are excluded before the
page limit; both a blocked
signing actor and a blocked boosted author are filtered, and a blocked
followed local account is omitted from the local half. See
[blocks](blocks.md). Remote entries have no local visibility field and are
serialized with `visibility: null`.

The single-post rule is enforced by `PostService::getVisible()`: a non-public
post answers 404 to anyone but its author, except that a followers-only post
is also readable by an accepted local follower and a direct post by one of its
recipients. The home timeline and the detail route therefore agree. `unlisted`
is publicly readable by design — it is only hidden from timelines.

## Timelines

`GET /api/v1/timelines/public` returns local `public` and `unlisted` posts,
newest first, with the id as the tie-break. Remote posts are not copied into
the public timeline.

`GET /api/v1/timelines/home` is the combined repository page: the caller's own
local posts and boosts plus live remote posts/boosts from followed remote
actors, plus posts and boosts from followed local actors, minus actors the
caller blocks. A local boost entry uses the boost id as `id`, the boosted post
as `object_id`/`object_author_id`, the boost time as `published_at`, and the
booster as `boosted_by`, so it is one entry rather than a second copy of the
post. The two halves are filtered, sorted together by
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
local read filters it, a `Delete` activity is queued for the audience, and an
AT Protocol mirror (when enabled and published) queues its own idempotent
remote delete.
Keeping the row means a retried `Delete` still has a stable object id to refer
to. There is no API route for fetching an individual remote post; remote
content is read through the home timeline.
