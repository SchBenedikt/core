# Federation

The core speaks ActivityPub over signed HTTP POSTs. It does not yet speak
WebFinger, host an actor document or accept inbound activities; those are host
and next-milestone concerns. What exists is the outbound half, end to end.

## Ids

Everything is derived from `social/instance.url`, so the same post always
produces the same document id — which is what makes a retry idempotent for the
receiver.

| Thing | Shape |
| --- | --- |
| Actor | `{base}/@{urlencoded user id}` |
| Key | `{actor}#main-key` |
| Followers collection | `{actor}/followers` |
| Note | `{base}/api/v1/posts/{urlencoded post id}` |
| Create activity | `{note}/activity` |
| Delete activity | `{note}/delete` |

## Documents

`Create` wraps a `Note`; `Delete` wraps a `Tombstone`. Content is declared as
`text/plain` — the core does not render HTML, and claiming `text/html` over an
unescaped string would be a cross-server XSS.

Audience mapping:

| Visibility | `to` | `cc` |
| --- | --- | --- |
| `public` | `as:Public` | followers collection |
| `unlisted` | followers collection | `as:Public` |
| `followers` | followers collection | — |
| `direct` | — | — |

## Signatures

`HttpSignature` implements draft-cavage HTTP signatures:

- the signing string is `(request-target) host date` and, for a body,
  `digest`;
- the digest is `SHA-256=` + base64 of the raw body;
- verification requires a signed digest whenever a body is present — a
  signature over headers alone would let anyone swap the payload;
- a `Date` outside the allowed window is refused;
- the key id must match the actor the host expects.

Verification never weakens to make a peer pass. If a peer signs differently,
log the signing string and look at the peer first.

## Delivery

Writing a post queues one job per inbox:

```json
{
  "inbox": "https://remote.example/users/bob/inbox",
  "activity": { "type": "Create", "...": "..." },
  "key_id": "https://local.example/@alice#main-key"
}
```

The activity is built **at write time** and stored in the job. An edit between
queueing and sending must not change what a retry sends. The worker signs each
request and treats a non-2xx answer as a retryable failure; the queue backs off
and eventually dead-letters.

## Not implemented yet

- inbound `POST /inbox` and signature verification of remote requests;
- `Accept`/`Reject` of a `Follow`, and `Undo`;
- actor documents, `followers`/`following` collections, WebFinger;
- delivery to shared inboxes;
- fetching remote posts (this core only publishes).

The seams are named in [the architecture](../architecture.md#what-is-not-here-yet),
so each of these is an addition, not a rewrite.
