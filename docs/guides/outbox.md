# Outbox

```http
GET /@{handle}/outbox
Accept: application/activity+json
```

The public `OrderedCollection` of an account's posts. It is the URL the
[actor document](../reference/actor-document.md) advertises as `outbox`, so a
peer that fetched the document never has to guess it.

## Root document

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/outbox",
  "type": "OrderedCollection",
  "totalItems": 1,
  "first": "https://social.example/@alice/outbox?page=1"
}
```

The root carries the count and the first-page link. It does not embed all
posts; a peer follows `first` and then the page `next` links.

## A page

```http
GET /@alice/outbox?page=1
Accept: application/activity+json
```

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/outbox?page=1",
  "type": "OrderedCollectionPage",
  "partOf": "https://social.example/@alice/outbox",
  "totalItems": 1,
  "orderedItems": [
    {
      "@context": "https://www.w3.org/ns/activitystreams",
      "id": "https://social.example/api/v1/posts/abc/activity",
      "type": "Create",
      "actor": "https://social.example/@alice",
      "published": "2026-11-11T10:00:00Z",
      "to": ["https://www.w3.org/ns/activitystreams#Public"],
      "cc": ["https://social.example/@alice/followers"],
      "object": {
        "id": "https://social.example/api/v1/posts/abc",
        "type": "Note",
        "attributedTo": "https://social.example/@alice",
        "content": "hello",
        "mediaType": "text/plain",
        "published": "2026-11-11T10:00:00Z",
        "to": ["https://www.w3.org/ns/activitystreams#Public"],
        "cc": ["https://social.example/@alice/followers"],
        "sensitive": false
      }
    }
  ]
}
```

`Content-Type` is `application/activity+json; charset=utf-8`. Pages are 1-based,
default to 20 items, and cap at 40. The generated `first`/`next`/`prev` links
carry a non-default `limit`; `next` is absent on the last page and `prev` is
absent on the first. The page query is a repository `LIMIT`/`OFFSET`; a peer
does not have to download every post to reach the older ones.

## What is in it

- `id` — exactly the URL from `ActivityFactory::outboxId()`, the same function
  the actor document uses. A test compares the document's `outbox` with this
  collection's `id`.
- `first` — the first page URL; its `partOf` points back at this collection.
- `orderedItems` — on a page, the `Create` documents from
  `ActivityFactory::createNote()`, newest first. Each `actor` is the canonical
  actor URL and each `object.id` is the canonical post URL; the activity id is
  the note id plus `/activity`.
- `totalItems` — the full number of visible posts, not just the current page.

Only publicly visible posts (`public`, `unlisted`) appear. The route is
unauthenticated; listing a `followers`-only or `direct` post here would hand
its content to anyone who knows the actor URL. Deleted posts are omitted.
This is a visibility filter on one repository query, not a second store.

## The same activity twice

Outbound delivery sends exactly the `Create` document this collection lists.
A peer that received a post in its inbox and later reads the outbox sees the
same activity id and can deduplicate it. That is why both paths call
`ActivityFactory::createNote()` instead of building JSON separately.

`POST /inbox` and `POST /inbox/shared` apply `Follow`, `Undo(Follow)`, `Create`, `Delete` and
`Announce`. The outbox is the read direction and delivery is the push
direction; both use the same activity id so a peer can reconcile them. See
[inbound federation](inbound-federation.md).

## How a peer consumes it

1. Fetch the actor document and keep its `outbox` URL.
2. Fetch that URL, then follow its `first` link.
3. Read each page's `orderedItems`; follow `next` until it is absent.
4. For each item, read `object`; `object.attributedTo` must equal the actor.
5. Match by activity id, not by content, when reconciling with a delivery.

## Errors

| Status | Code | When |
| --- | --- | --- |
| 404 | `actor_not_found` | Unknown handle; the message does not echo it |
| 503 | `actor_not_configured` | No signing key or no local actor directory configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing |
