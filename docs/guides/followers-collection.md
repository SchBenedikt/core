# Followers collection

```http
GET /@{handle}/followers
Accept: application/activity+json
```

An `OrderedCollection` of the actors that follow a local account. It is public
and reads the same table the inbox writes.

This is the incoming direction. The actors this account follows are served at
`/@{handle}/following` from a separate outgoing table; `POST
/api/v1/followers` does not affect it. See the
[following collection](following-collection.md).

## Example

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/followers",
  "type": "OrderedCollection",
  "totalItems": 2,
  "orderedItems": [
    "https://remote.example/users/carol",
    "https://remote.example/users/bob"
  ]
}
```

`Content-Type` is `application/activity+json; charset=utf-8`. `orderedItems`
contains actor ids, newest follower first; `totalItems` is the count of the
same rows. There is no paging yet: the collection is complete, and an instance
with a very large follower list needs a `first`/`next` split in a later
milestone (listed in the [roadmap](../roadmap.md)).

## What changes it

| Event | Effect |
| --- | --- |
| `POST /inbox` with a valid `Follow` | The actor appears at the front of `orderedItems` |
| `POST /inbox` with `Undo(Follow)` | The actor disappears |
| `POST /api/v1/followers` (local API) | Same table, so the actor appears as well |
| `DELETE /api/v1/followers/{id}` | The actor disappears |

A repeated `Follow` does not duplicate the entry: `(local user, actor)` is
unique. A repeated `Undo` is a no-op, so the collection stays empty.

## Errors

| Status | Code | When |
| --- | --- | --- |
| 404 | `actor_not_found` | Unknown handle; the message does not echo it |
| 503 | `actor_not_configured` | No signing key or no local actor directory configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing |

## Why the key is required

The collection itself needs no key. It is disabled together with the actor
document on purpose: an instance that cannot publish the account's key is not
ready to be discovered, and a reachable collection without an actor document
would be a half-working identity. See the
[actor document reference](../reference/actor-document.md).
