# Following collection

```http
GET /@{handle}/following
Accept: application/activity+json
```

An `OrderedCollection` of the actors a local account follows. It is public and
reads the outgoing follow graph for remote follows, plus local-to-local edges
from the incoming graph that the Mastodon-compatible follow route writes.

## Root document and pages

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/following",
  "type": "OrderedCollection",
  "totalItems": 2,
  "first": "https://social.example/@alice/following?page=1"
}
```

The root advertises the first page. `GET /@alice/following?page=1` returns an
`OrderedCollectionPage`:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/following?page=1",
  "type": "OrderedCollectionPage",
  "partOf": "https://social.example/@alice/following",
  "totalItems": 2,
  "orderedItems": [
    "https://remote.example/users/carol",
    "https://remote.example/users/bob"
  ]
}
```

`Content-Type` is `application/activity+json; charset=utf-8`. `orderedItems`
contains absolute ActivityPub actor URLs, newest follow first. `totalItems` is
the full count. Pages are 1-based, default to 20 items, and cap at 40; `next`
and `prev` carry the page and any non-default limit. Repository page queries
back the collection, so a peer can walk it without downloading every edge.

## Source of truth

`FollowingRepository` (`social_following`) stores the outgoing edge. It is
separate from `social_follower` on purpose: a follower is an inbound edge, a
followed actor is an outbound edge. Reusing the follower table would make peers
read "who follows alice" as "whom alice follows".

A local-to-local Mastodon follow is the one deliberate exception: it is stored
in `social_follower` as a local edge (`is_local = 1`) so the target's followers
collection and counts stay exact. `ActorDocumentService::following` combines
those local actor ids with the outgoing repository's page, and the root
`totalItems` adds both counts. The incoming graph still never answers the
following URL on its own, and local edges are skipped by remote delivery.

```
id                 VARCHAR(64)  primary key
local_user_id      VARCHAR(255)
actor_id           VARCHAR(255) unique with local_user_id
inbox_url          VARCHAR(512)
followed_at        VARCHAR(20)  canonical UTC timestamp
state              VARCHAR(16)  pending | accepted | rejected
follow_activity_id VARCHAR(512) unique nullable
```

The inbox is stored with the edge because a `Follow` and its later `Undo` have
to be delivered there. It is not the inbox posts are delivered to: that belongs
to the incoming follower graph. `follow_activity_id` is the exact id a peer
may embed in an `Accept` or `Reject`; it is nullable so rows created before the
lifecycle existed remain readable and default to `accepted`.

The collection id comes from `ActivityFactory::followingId()`, the same
function the [actor document](../reference/actor-document.md) uses for its
`following` field. A test fetches `/@alice/following` and compares the
collection id with the document value, so the two cannot drift apart.
`FollowingRepository::actorIdsPage()` supplies one page; `count()` supplies the
root count. Both read only `state = accepted`: a pending request is not yet a
follow, and a rejected request must never appear in the public collection.

## Writing a follow

```http
POST /api/v1/following
Content-Type: application/json

{"actor": "https://remote.example/users/bob",
 "inbox": "https://remote.example/users/bob/inbox"}
```

`201` with `Location: /api/v1/following/{id}` when the edge is new, `200` when
the actor is already followed or still pending:

```json
{"following": {"id": "…", "actor": "https://remote.example/users/bob",
  "inbox": "https://remote.example/users/bob/inbox",
  "followed_at": "2026-11-11T10:00:00Z", "state": "pending"}}
```

The request stores the pending edge and queues a `federation.follow` job. The
worker delivers the signed ActivityPub `Follow` to the supplied inbox; a
failed attempt stays queued and is retried with the queue's backoff. The API
answer does not wait for the peer:

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
is updated. That keeps a retried request from producing duplicate Follows. A
rejected edge is not reused: following again creates a new row and a new
Follow id, so a late response for the old request cannot match it.

Both `actor` and `inbox` must be absolute http(s) URLs; a bare handle or a
relative path is a 422 naming the field.

## Unfollowing

```http
DELETE /api/v1/following/{id}
```

`204` after the edge was removed and the `Undo` was queued. The `Undo` embeds
the original `Follow`, so a peer can match it by id without having stored the
activity:

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

## Lifecycle and delivery semantics

A new edge starts `pending`. The only transitions are
`pending → accepted` and `pending → rejected`, and only a signed `Accept` or
`Reject` from the followed actor can make them. The repository applies the
transition with `UPDATE … WHERE state = 'pending'`, so duplicate responses, a
late response after an Unfollow and two competing responses are all safe:

- a duplicate or wrong-order response changes nothing and still answers `202`;
- `Accept` after `Reject` does not resurrect the edge;
- an `Accept`/`Reject` for a removed row is a no-op;
- an embedded Follow must have the local actor as `actor` and the followed
  actor as `object`; a foreign signer or a mismatched embedded Follow is `403`.

The collection and the home timeline only read accepted rows. A pending edge
is visible in the write response, but not to peers until accepted. A rejected
row stays for the lifecycle audit and is removed by following again or by
unfollowing; it is never in the collection.

| Situation | Local state | Answer |
| --- | --- | --- |
| New Follow | pending, one `federation.follow` job queued | 201 |
| Follow already pending or accepted | unchanged, no second job | 200 |
| Follow after Reject | old row removed, new pending row and job | 201 |
| Follow delivery refused or unreachable | pending retained, queue retries | 201, then worker retry |
| Accept | pending → accepted | 202 at the inbox; collection gains the actor |
| Reject | pending → rejected | 202 at the inbox; collection never had it |
| Duplicate Accept/Reject | unchanged | 202 |
| Accept after Unfollow | no row, no change | 202 no-op |
| Unfollow | row removed, `federation.follow` undo job queued | 204 |
| Undo delivery refused or unreachable | row already gone, queue retries the Undo | 204, then worker retry |
| No HTTP client or signing key | nothing written | 503 `federation_not_configured` |

The queue is the source of delivery state. `federation.follow` jobs carry the
exact activity and inbox; a retry sends the same Follow or Undo. Before a
Follow delivery the worker re-reads the row and skips a job whose edge was
removed by an Unfollow. That prevents the common race where a queued Follow
would otherwise arrive after its Undo.

## What changes it

| Event | Effect |
| --- | --- |
| `POST /api/v1/following` | A pending row is stored at the front; it appears only after Accept |
| `DELETE /api/v1/following/{id}` | The actor disappears immediately; the Undo is queued |
| Signed `Accept` at `/inbox` | The pending row becomes accepted |
| Signed `Reject` at `/inbox` | The pending row becomes rejected |
| `FollowingService::follow('alice', $actor, $inbox)` | Same as the endpoint, for hosts that call the service directly |
| `FollowingService::unfollowById('alice', $id)` | Same as the endpoint |

Following is idempotent by `(local user, actor)`: a repeated follow returns the
existing edge, so the collection cannot gain duplicates. The collection is
scoped per account: two local accounts can follow the same actor independently.

`POST /api/v1/followers` and an inbound `Follow` do **not** change this
collection: they record who follows the account, the opposite direction. See
the [followers collection](followers-collection.md).

The follow-based home timeline reads this same graph: `HomeTimelineRepository`
asks `FollowingRepository::actorIds()` for the reader and joins remote
posts/boosts to those actors. Unfollowing removes the actor's remote content
from the reader's next page without deleting the shared remote rows.

## Errors

| Status | Code | When |
| --- | --- | --- |
| 401 | `authentication_required` | No authenticated user |
| 404 | `not_found` | Unknown id, or the edge belongs to another account |
| 404 | `actor_not_found` | Unknown handle on the read route; the message does not echo it |
| 422 | `validation_failed` | `actor` or `inbox` is not an absolute http(s) URL |
| 503 | `actor_not_configured` | The read route has no signing key or local actor directory |
| 503 | `federation_not_configured` | A write has no HTTP client or signing key to deliver with |
| 503 | `instance_not_configured` | `social/instance.url` is missing |

The read collection needs no key. It is disabled with the actor surface on
purpose: if the actor document would answer 503, a collection without a
readable actor would be half an identity.
