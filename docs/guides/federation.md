# Federation

The core speaks ActivityPub over signed HTTP POSTs, serves its actor
documents and resolves handles through WebFinger. It also accepts inbound
`Follow`, `Undo(Follow)`, `Create`, `Delete` and `Announce` activities.

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

Writing a post queues one job per **effective** inbox. When a follower
advertised a shared inbox, that URL replaces the individual one; followers on
the same server are de-duplicated:

```json
{
  "inbox": "https://remote.example/inbox/shared",
  "activity": { "type": "Create", "...": "..." },
  "key_id": "https://local.example/@alice#main-key"
}
```

The activity is built **at write time** and stored in the job. An edit between
queueing and sending must not change what a retry sends. The worker signs each
request and treats a non-2xx answer as a retryable failure; the queue backs off
and eventually dead-letters.

A local `Follow`/`Undo` is not queued: the endpoint has to know whether the
peer accepted it before it can answer. `FollowingService` signs and delivers
it synchronously through the same `FederationDeliveryHandler`, rolls a Follow
back when the peer refuses it, and keeps an edge when an Undo fails so the
request can be retried. See the [following collection](following-collection.md).

## Inbound

The other direction is implemented too: `POST /inbox` and `POST /inbox/shared`
verify the signature of a remote actor, apply `Follow`/`Undo(Follow)`, and store
`Create`, `Delete` and `Announce` from actors followed by at least one local
account. The actor document advertises the shared route under
`endpoints.sharedInbox`; see [shared inboxes](shared-inboxes.md).
The home timeline reads those remote rows through the outgoing follow graph
and applies each reader's private block list; see [blocks](blocks.md).
See [inbound federation](inbound-federation.md) for the order of checks and
[inbox activities](../reference/inbox-activities.md) for the refusal table.

## Discovery

A local account is described by its [actor document](../reference/actor-document.md)
at `/@{handle}`, including the public key and the inbox. A peer finds it from
`@handle@host` through [WebFinger](webfinger.md), which returns the document
URL as a `self` link. The [followers](followers-collection.md) and
[following](following-collection.md) collections and the [outbox](outbox.md)
are served next to it; the outbox lists the account's public posts as the same
`Create` activities delivery sends.

## Not implemented yet

The local federation path is complete for the features in the roadmap: outbox
backfill, thread/context fetching, remote media and shared-inbox batching are
implemented. Scheduling the backfill, thread retention and host-specific
policy remain host operations. AT Protocol and a Mastodon-compatible API are
still separate roadmap items; the seams are named in
[the architecture](../architecture.md#what-is-not-here-yet), so each is an
addition, not a rewrite.
