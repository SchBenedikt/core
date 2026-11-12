# Remote threads and context

`GET /api/v1/remote-posts/{id}/thread` returns a bounded context view around a
remote post that is already stored locally. It is an explicit, authenticated
read: the core does not mirror replies in the background and does not walk the
whole conversation.

## Request

```http
GET /api/v1/remote-posts/https%3A%2F%2Fremote.example%2Factivities%2Fcreate-1/thread
Authorization: Bearer …
```

`{id}` is the remote **activity id**, not the object URL. It is URL-encoded as
one path segment; the router decodes it after matching, so `%2F` cannot create
a new segment. Clients get the activity id as `id` on a home-timeline entry.
Object ids are inside the response as `object_id`.

The target must be a live `social_remote_post` whose signing actor the caller
follows and does not block. Missing, deleted, unfollowed and blocked targets
all answer the same `404 not_found`; the route does not reveal whether a
remote post exists for another account.

## Response

```json
{"thread": {
  "post": {
    "object_id": "https://remote.example/notes/target",
    "author_id": "https://remote.example/users/bob",
    "content": "target content",
    "content_warning": null,
    "published_at": "2026-11-11T10:02:00Z",
    "in_reply_to_id": "https://remote.example/notes/parent"
  },
  "ancestors": [],
  "replies": []
}}
```

- `ancestors` is ordered oldest/root first and ends directly before the target.
  The walk follows `inReplyTo` up to `social/thread.max_depth` (default 5,
  maximum 10). `0` returns no ancestors.
- `replies` contains **direct** children only: an object is included only when
  its `inReplyTo` is exactly the target object id. It does not walk a reply of
  a reply. Replies are oldest first and bounded by
  `social/thread.max_replies` (default 20, maximum 40).
- Ancestors and replies whose author the caller blocks are omitted. The target
  itself must not be blocked.
- A reply activity wrapped in `Create` is unwrapped. `Tombstone` objects,
  objects without id, author or content, and malformed timestamps are skipped.
- No media is imported. Attachments in a fetched thread node are not stored as
  local blobs and are not part of this response shape.

## Where the data comes from

The target is fetched from its `object_id` with the `Accept` header for
ActivityStreams. An embedded `replies` collection is used when present;
otherwise its `first` page is fetched once. `next` is deliberately never
followed. Ancestors are read from the thread cache when possible; otherwise
they are fetched and cached. Replies are fetched from the advertised
collection, or from the cache when a previous lookup failed.

Every fetched node is upserted into `social_remote_thread_post` under its
object id. Repeating the request, or reaching the same object as a parent in a
second thread, updates that one row instead of creating a duplicate. The cache
is separate from `social_remote_post` because a context node may have no
delivered `Create` activity and must not appear as a standalone timeline entry.

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `social/thread.fetch` | `true` | Enables the route. `false` answers `503 thread_fetch_disabled` without contacting the peer. |
| `social/thread.max_depth` | `5` | Ancestors walked; clamped to 0–10. |
| `social/thread.max_replies` | `20` | Direct replies returned; clamped to 0–40. |
| `social/thread.max_bytes` | `1 MiB` | Body limit for one object or collection; clamped to 1 byte–5 MiB. |
| `social/thread.timeout_seconds` | `10` | Per-hop timeout; clamped to 1–30. |
| `social/thread.max_redirects` | `3` | Redirects per fetch; clamped to 0–5. |

The route is disabled when no bounded HTTP client is assembled
(`503 thread_not_configured`). A host that supplies only `HttpClient` and not
`BoundedHttpClient` cannot safely enable threads; the assembly leaves the
service unset rather than falling back to an unchecked request. Disabling
`thread.fetch` does not delete cached rows, and it does not return an empty or
stale thread as if it were current.

Remote media and threads use the same network guard
(`Service\Federation\RemoteFetcher`): only http(s), no userinfo, DNS and every
resolved address checked against private/loopback/link-local/CGNAT/
documentation ranges, every redirect revalidated, timeouts and a hard body
limit. They have separate switches and limits.

## Failures

A failure to fetch or parse the target is `502 thread_fetch_failed`; the reason
is logged with a stable `thread_*` value (`thread_timeout`,
`thread_too_large`, `thread_ssrf_blocked`, `thread_invalid_document`, and so
on). A failed ancestor fetch truncates the ancestor chain but still returns the
target and replies. A failed reply collection falls back to cached children;
if none are cached, it returns the target with an empty reply list. A failed
cache write is logged as `thread_store_failed`; the fetched response is still
returned, and the next request retries the idempotent upsert. No HTTP error is
invented for a partial context view.

An ancestor or reply from a blocked actor is dropped **after** fetching (the
author is only known from the document). The cache never stores a node that
was dropped by the reader's block. This is a display filter, not a signed
`Block`; no remote request is made to the blocked actor beyond the context
fetch itself.

## Retention and cleanup

The core does not schedule a TTL. A host that wants to bound the context cache
calls:

```php
$threads->deleteFetchedBefore($cutoff);
```

on its own schedule. The `fetched_at` index supports this query. Deleting cache
rows does not touch remote posts, attachments, blobs or timeline rows. A later
request refetches and repopulates the context. This repository has no migration
runner; create `social_remote_thread_post` from
`RemoteThreadSchema::statements()` in the host's migration path.

## Limitations

- Only one collection page is read; there is no `next` paging or a "load more
  replies" route.
- Remote deletions and edits are not pushed into the cache. A cached ancestor
  can be stale until the host expires it or a later context request refreshes
  that object.
- The endpoint fetches on every request (at least the target); it is not a
  fully offline cache. The cache saves repeated ancestor requests, not the
  authoritative freshness check.
- Thread text is HTML from the remote object and is returned as stored; this
  route has no local media objects and does not sanitize rich text. A client
  must treat remote HTML as untrusted.
- No media import, no reactions, no reply pagination and no full-thread mirror.
