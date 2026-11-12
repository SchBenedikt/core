# Following collection

```http
GET /@{handle}/following
Accept: application/activity+json
```

An `OrderedCollection` of the actors a local account follows. It is public and
reads the outgoing follow graph — not the followers table.

## Example

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/following",
  "type": "OrderedCollection",
  "totalItems": 2,
  "orderedItems": [
    "https://remote.example/users/carol",
    "https://remote.example/users/bob"
  ]
}
```

`Content-Type` is `application/activity+json; charset=utf-8`. `orderedItems`
contains absolute ActivityPub actor URLs, newest follow first. `totalItems`
is the length of the same list. There is no paging yet; a `first`/`next` split
is listed in the [roadmap](../roadmap.md).

## Source of truth

`FollowingRepository` (`social_following`) stores the outgoing edge. It is
separate from `social_follower` on purpose: a follower is an inbound edge, a
followed actor is an outbound edge. Reusing the follower table would make peers
read "who follows alice" as "whom alice follows".

```
id            VARCHAR(64)  primary key
local_user_id VARCHAR(255)
actor_id      VARCHAR(255) unique with local_user_id
inbox_url     VARCHAR(512)
followed_at   VARCHAR(20)  canonical UTC timestamp
```

The inbox is stored with the edge because a `Follow` and its later `Undo` have
to be delivered there. It is not the inbox posts are delivered to: that belongs
to the incoming follower graph.

The collection id comes from `ActivityFactory::followingId()`, the same
function the [actor document](../reference/actor-document.md) uses for its
`following` field. A test fetches `/@alice/following` and compares the
collection id with the document value, so the two cannot drift apart.

## Writing a follow

```http
POST /api/v1/following
Content-Type: application/json

{"actor": "https://remote.example/users/bob",
 "inbox": "https://remote.example/users/bob/inbox"}
```

`201` with `Location: /api/v1/following/{id}` when the edge is new, `200` when
the actor is already followed:

```json
{"following": {"id": "…", "actor": "https://remote.example/users/bob",
  "inbox": "https://remote.example/users/bob/inbox",
  "followed_at": "2026-11-11T10:00:00Z"}}
```

The request stores the edge and delivers a signed ActivityPub `Follow` to the
supplied inbox:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/api/v1/following/{id}/follow",
  "type": "Follow",
  "actor": "https://social.example/@alice",
  "object": "https://remote.example/users/bob",
  "to": ["https://remote.example/users/bob"]
}
```

The delivery is signed with the local actor's key over `(request-target) host
date digest`, using the key id the actor document publishes. A repeated
`POST` sends nothing: the existing edge is returned, and only a changed inbox
is updated. That keeps a retried request from producing duplicate Follows.

Both `actor` and `inbox` must be absolute http(s) URLs; a bare handle or a
relative path is a 422 naming the field.

## Unfollowing

```http
DELETE /api/v1/following/{id}
```

`204` after the peer accepted the `Undo`. The `Undo` embeds the original
`Follow`, so a peer can match it by id without having stored the activity:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/api/v1/following/{id}/follow/undo",
  "type": "Undo",
  "actor": "https://social.example/@alice",
  "object": {
    "id": "https://social.example/api/v1/following/{id}/follow",
    "type": "Follow",
    "actor": "https://social.example/@alice",
    "object": "https://remote.example/users/bob",
    "to": ["https://remote.example/users/bob"]
  },
  "to": ["https://remote.example/users/bob"]
}
```

An id of another account is answered with 404, not 403: the id alone must not
reveal that another account follows the actor.

## Delivery semantics

| Situation | Local state | Answer |
| --- | --- | --- |
| Follow accepted by the peer | edge stored | 201 |
| Follow already existed | unchanged, no second delivery | 200 |
| Follow delivery refused or unreachable | edge rolled back | 502 `delivery_failed` |
| Undo accepted by the peer | edge removed | 204 |
| Undo delivery refused or unreachable | edge kept, request retryable | 502 `delivery_failed` |
| No HTTP client or signing key | nothing written | 503 `federation_not_configured` |

The order is deliberate. A Follow is inserted first and removed again if the
peer refuses it, because the collection must not claim a follow the peer never
saw. An Undo is sent first and the edge is removed only after the peer
accepted it; forgetting the follow locally while the peer still has it would
be the inconsistent state this path is meant to avoid. A retry of the same
`DELETE` sends the same `Undo` again.

## What changes it

| Event | Effect |
| --- | --- |
| `POST /api/v1/following` | The actor appears at the front |
| `DELETE /api/v1/following/{id}` | The actor disappears |
| `FollowingService::follow('alice', $actor, $inbox)` | Same as the endpoint, for hosts that call the service directly |
| `FollowingService::unfollowById('alice', $id)` | Same as the endpoint |

Following is idempotent by `(local user, actor)`: a repeated follow returns the
existing edge, so the collection cannot gain duplicates. The collection is
scoped per account: two local accounts can follow the same actor independently.

`POST /api/v1/followers` and an inbound `Follow` do **not** change this
collection: they record who follows the account, the opposite direction. See
the [followers collection](followers-collection.md).

A follow-based home timeline that reads this graph is still open (see the
[roadmap](../roadmap.md)); the collection itself is now writable through the
endpoint above.

## Errors

| Status | Code | When |
| --- | --- | --- |
| 401 | `authentication_required` | No authenticated user |
| 404 | `not_found` | Unknown id, or the edge belongs to another account |
| 404 | `actor_not_found` | Unknown handle on the read route; the message does not echo it |
| 422 | `validation_failed` | `actor` or `inbox` is not an absolute http(s) URL |
| 502 | `delivery_failed` | The peer answered non-2xx or could not be reached |
| 503 | `actor_not_configured` | The read route has no signing key or local actor directory |
| 503 | `federation_not_configured` | A write has no HTTP client or signing key to deliver with |
| 503 | `instance_not_configured` | `social/instance.url` is missing |

The read collection needs no key. It is disabled with the actor surface on
purpose: if the actor document would answer 503, a collection without a
readable actor would be half an identity.
