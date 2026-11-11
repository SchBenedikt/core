# Outbox

```http
GET /@{handle}/outbox
Accept: application/activity+json
```

The public `OrderedCollection` of an account's posts. It is the URL the
[actor document](../reference/actor-document.md) advertises as `outbox`, so a
peer that fetched the document never has to guess it.

## Example

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://social.example/@alice/outbox",
  "type": "OrderedCollection",
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

`Content-Type` is `application/activity+json; charset=utf-8`.

## What is in it

- `id` — exactly the URL from `ActivityFactory::outboxId()`, the same function
  the actor document uses. A test compares the document's `outbox` with this
  collection's `id`.
- `orderedItems` — the `Create` documents from
  `ActivityFactory::createNote()`, newest first. Each `actor` is the canonical
  actor URL and each `object.id` is the canonical post URL; the activity id is
  the note id plus `/activity`.
- `totalItems` — the length of `orderedItems`. The collection is complete and
  not paged yet, so the two always agree.

Only publicly visible posts (`public`, `unlisted`) appear. The route is
unauthenticated; listing a `followers`-only or `direct` post here would hand
its content to anyone who knows the actor URL. Deleted posts are omitted.
This is a visibility filter on one repository query, not a second store.

## The same activity twice

Outbound delivery sends exactly the `Create` document this collection lists.
A peer that received a post in its inbox and later reads the outbox sees the
same activity id and can deduplicate it. That is why both paths call
`ActivityFactory::createNote()` instead of building JSON separately.

`POST /inbox` currently applies only `Follow` and `Undo(Follow)`, so the
outbox is the read direction and delivery is the push direction; inbound
posts are a later milestone.

## How a peer consumes it

1. Fetch the actor document and keep its `outbox` URL.
2. Fetch that URL.
3. For each item, read `object`; `object.attributedTo` must equal the actor.
4. Match by activity id, not by content, when reconciling with a delivery.

There is no paging yet: a peer that wants older posts gets them all in one
response. Pagination for the outbox and the followers collection is on the
[roadmap](../roadmap.md).

## Errors

| Status | Code | When |
| --- | --- | --- |
| 404 | `actor_not_found` | Unknown handle; the message does not echo it |
| 503 | `actor_not_configured` | No signing key or no local actor directory configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing |
