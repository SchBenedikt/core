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
separate from `social_follower` on purpose: a follower is an inbound edge with
an inbox, a followed actor is an outbound edge without one. Reusing the
follower table would make peers read "who follows alice" as "whom alice
follows".

The collection id comes from `ActivityFactory::followingId()`, the same
function the [actor document](../reference/actor-document.md) uses for its
`following` field. A test fetches `/@alice/following` and compares the
collection id with the document value, so the two cannot drift apart.

## What changes it

| Event | Effect |
| --- | --- |
| `FollowingService::follow('alice', $actor)` | The actor appears at the front |
| `FollowingService::unfollow('alice', $actor)` | The actor disappears |

Following is idempotent by `(local user, actor)`: a repeated call returns the
existing edge, so the collection cannot gain duplicates.

`POST /api/v1/followers` and an inbound `Follow` do **not** change this
collection: they record who follows the account, the opposite direction. See
the [followers collection](followers-collection.md).

There is no local follow HTTP route yet. The write path is the
`FollowingService` the reference assembly exposes; outbound `Follow` delivery
and a follow-based home timeline are still open (see the
[roadmap](../roadmap.md)).

## Errors

| Status | Code | When |
| --- | --- | --- |
| 404 | `actor_not_found` | Unknown handle; the message does not echo it |
| 503 | `actor_not_configured` | No signing key or no local actor directory configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing |

The collection itself needs no key. It is disabled with the actor surface on
purpose: if the actor document would answer 503, a collection without a
readable actor would be half an identity.
