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

## Root document

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/followers",
  "type": "OrderedCollection",
  "totalItems": 2,
  "first": "https://social.example/@alice/followers?page=1"
}
```

The root carries the count and the first-page link. It deliberately does not
embed the items; a peer follows `first` and then the page `next` links. The
collection `id` is exactly the `followers` URL the actor document advertises.

## A page

```http
GET /@alice/followers?page=1
Accept: application/activity+json
```

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/followers?page=1",
  "type": "OrderedCollectionPage",
  "partOf": "https://social.example/@alice/followers",
  "totalItems": 2,
  "orderedItems": [
    "https://remote.example/users/carol",
    "https://remote.example/users/bob"
  ]
}
```

`Content-Type` is `application/activity+json; charset=utf-8`. `orderedItems`
contains actor ids, newest follower first. `totalItems` is the full count, not
the length of this page.

Pages are 1-based. The default page size is 20 and the maximum is 40; a client
may pass `limit` and the generated `first`/`next`/`prev` links keep a
non-default limit. `next` exists only while another page follows, and `prev`
exists for every page after the first. Page queries are repository-backed with
`LIMIT`/`OFFSET`; the HTTP layer never builds one large collection and slices
it.

## What changes it

| Event | Effect |
| --- | --- |
| `POST /inbox` with a valid `Follow` | The actor appears at the front of the first page |
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
