# Federation

The core speaks ActivityPub over signed HTTP POSTs, serves its actor
documents and resolves handles through WebFinger. It also accepts inbound
`Follow`, `Undo(Follow)`, `Create`, `Delete`, `Announce` and `Undo(Announce)`
activities.

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
unescaped string would be a cross-server XSS. A reply adds the parent URL as
`inReplyTo`.

A `Block` has the blocked actor URL as its `object` and `to: [actor]`.

## Typed remote objects

Not every remote object is a plain Note. The remote store keeps a bounded
`object_type` — `note`, `article`, `page`, `question`, `event`, `audio` or
`video` — separately from the boost kind, so an `Announce` of an Event stays a
boost and a media-only Video is still a Video. The neutral projection returns
`object_type` and an optional `metadata` object; clients should prefer it over
sniffing the body.

| Type | Preserved metadata |
| --- | --- |
| `article`, `page`, `event` | plain-text `title` |
| `event` | `name`, `starts_at`, `ends_at`, `location`, `participation_url`, `status` |
| `audio`, `video` | `duration_seconds`, `thumbnail_url`, keyed `sources` |
| `question` | `title`; the vote lifecycle itself is tracked separately |

An object may arrive with an empty body when it has typed metadata: a
Mobilizon or Gancio Event, an Article and a PeerTube Video are all renderable
without text. An object with neither body nor metadata is still refused.

Validation happens in `RemotePostFactory`, shared by the inbox and outbox
backfill: titles and locations are stripped of HTML, event timestamps must be
RFC 3339 and an end before the start is refused, and every URL — including a
Mobilizon `externalParticipationUrl` — must be absolute `http(s)` with no
credentials. A `javascript:` link therefore cannot reach a participation
button. An unknown `eventStatus` is ignored (the card simply shows no status)
and a cancelled event stays a stored post: cancellation is metadata, not a
`Delete`. Durations are parsed from ISO 8601 and an out-of-range or malformed
one is dropped rather than failing the delivery. An edit keeps the stored
type and metadata and applies only the updated text; a boost without an
embedded object inherits the target's type and metadata.
`Undo(Block)` wraps that activity. Both use the same signed delivery queue as
posts, but no post audience is involved.

Audience mapping:

| Visibility | `to` | `cc` |
| --- | --- | --- |
| `public` | `as:Public` | followers collection |
| `unlisted` | followers collection | `as:Public` |
| `followers` | followers collection | — |
| `direct` | local recipient actor URLs | — |

Direct posts are not delivered over the network; their `to` field is built for
an outbox reader and names the local recipients. The write path skips remote
delivery entirely.

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
queueing and sending must not change what a retry sends. Before signing, the
worker passes the inbox through `FederationTargetGuard`: only a public http(s)
target is accepted, and a bounded client pins the addresses that were checked.
The worker signs each request and treats a non-2xx answer as a retryable
failure; the queue backs off and eventually dead-letters.

A local `Follow`/`Undo` is queued as `federation.follow`. The endpoint stores
the pending state or removes the edge, and the worker sends the signed activity
through the same `FederationDeliveryHandler`; a failed attempt is retried with
the queue backoff. Before sending a Follow the worker re-reads the row, so an
Unfollow removes the edge and the stale Follow job becomes a no-op. A signed
`Accept`/`Reject` from the followed actor completes the pending request. See
the [following collection](following-collection.md).

Per-user blocks are queued asynchronously instead. The local block is stored
before the actor document is resolved; when the actor's inbox is known, a
signed `Block` or `Undo(Block)` job is added. See [blocks](blocks.md).

## Inbound

The other direction is implemented too: `POST /inbox` and `POST /inbox/shared`
verify the signature of a remote actor, apply `Follow`/`Undo(Follow)` and
`Undo(Announce)`, and store `Create`, `Delete` and `Announce` from actors
followed by at least one local
account. The actor document advertises the shared route under
`endpoints.sharedInbox`; see [shared inboxes](shared-inboxes.md).
Rich objects are imported with their typed metadata as described above.
The home timeline reads those remote rows through the outgoing follow graph
and applies each reader's private block list; see [blocks](blocks.md).
See [inbound federation](inbound-federation.md) for the order of checks and
[inbox activities](../reference/inbox-activities.md) for the refusal table.

## Remote polls

An ActivityPub `Question` is imported beside its `Create` activity. The store
keeps the peer's option titles and counts, whether the question is `oneOf`
(single choice) or `anyOf` (multiple choice), the `endTime` and an explicit
`closed` timestamp. Option counts come from `replies.totalItems`; our own
votes live in a separate table and are added when the poll is presented, so a
later peer update cannot double-count them.

The neutral API is `GET /api/v1/remote-polls/{id}` and
`POST /api/v1/remote-polls/{id}/votes` with `choices` as option positions. A
vote is refused after `endTime` or `closed`, for an unknown option, for more
than one option in a single-choice poll and for a second, different vote by
the same account. An identical retry is idempotent. The stored vote is
optimistic: `delivery` is `queued` once one signed `Create(Note)` per choice
has been handed to the delivery queue, or `failed` when queueing did not work
and the retry path may try again.

Inbound votes on local polls use the Mastodon convention: a signed
`Create(Note)` whose `inReplyTo` is the local post URL and whose `name` is one
of the option titles. The activity is recorded in the local vote table and
is not imported as a reply; a replay of the same choice is a no-op and a
different choice is refused. A scheduled `poll.close` job marks an imported
Question closed at its end time, while a local poll derives its expired state
from its own stored expiry.

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
implemented. Scheduling the backfill and the `cleanup` job remain host
operations; the thread-retention rules themselves are now implemented. The AT Protocol publishing foundation is now in
the core, while account linking/OAuth and the full repository/AppView stack
remain external. The local Mastodon-compatible subset is implemented under its
own disabled-by-default prefix; only its remote server remainder is external.
The seams are named in [the architecture](../architecture.md#what-is-not-here-yet),
so each is an addition, not a rewrite. See
[AT Protocol publishing](atproto.md).
