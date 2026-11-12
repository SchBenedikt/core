# Inbound federation

`POST /inbox` is the only write endpoint that does not use a session. Its
credential is the HTTP signature: the request proves that the sender controls
the key of the actor it claims to be.

## Prerequisites

The endpoint is enabled only when the host provides **all three**:

| Piece | Why |
| --- | --- |
| an `ActorResolver` | to turn the `keyid` into an actor and its public key |
| an HTTP client | to deliver the `Accept` for a Follow |
| a signing key | to sign that `Accept` as the local actor |

Missing any of them, the handler answers `503 inbox_not_configured`. It does
not accept activities it could neither verify nor answer. The reference
assembly (`Application::inMemory()`) is therefore disabled by default.

A host may additionally pass a `BlockedActorPolicy`. It is optional and
instance-wide: it refuses an actor's inbound content before anything is
stored. Without one, `NoBlockedActors` allows every verified actor. Per-user
blocks are separate; they hide shared remote rows from one reader's home
timeline and do not reject a delivery for other followers. See
[blocks](blocks.md).

A `FollowPolicy` can refuse an inbound Follow for a specific local account
before the follower edge is written; `AcceptAllFollows` is the default. A
refusal answers `403 follow_not_accepted`, changes no graph row and releases
the activity claim, so the same signed Follow can be retried if the host later
accepts it.

## Order of checks

Every step refuses before anything changes:

1. **Configured?** — 503 `inbox_not_configured`.
2. **JSON object?** — 400 `invalid_json`. There is nothing to verify yet.
3. **`Signature` header present?** — 401 `signature_required`.
4. **Header parseable, `keyid` present?** — 401 `invalid_signature`.
5. **Key id resolvable?** — 404 `unknown_actor`. The answer does not say
   whether the document was missing, unreachable or malformed.
6. **Signature verifies** over method, path, `Host`, `Date` and the body
   `Digest`, within 300 seconds? — 401 `invalid_signature`, or 401
   `signature_expired` for a stale `Date`.
7. **Activity has string `id`, `type`, `actor`?** — 400 `invalid_activity`.
8. **Activity actor owns the key?** — 403 `actor_mismatch`.
9. **Actor blocked by the host policy?** — 403 `blocked_actor`.
10. **Activity id not seen before?** — 400 `replayed_activity`.
11. **Type supported?** — 400 `unsupported_activity`.
12. **For a `Follow`: does the local account's `FollowPolicy` accept it?** —
    403 `follow_not_accepted`.
13. **For `Create`, `Delete` and `Announce`: does at least one local account
    follow the actor?** — 403 `not_followed`.
14. **Object belongs to the signing actor?** — 403 `actor_mismatch`.

A refusal releases the replay claim when the claim was already taken, so a peer
that fixes its document can deliver the same activity again. A blocked actor is
refused before a claim, and a replay is refused without changing anything.

## Supported activities

| Type | Effect |
| --- | --- |
| `Follow` | stores the follower edge when the optional `FollowPolicy` accepts it (idempotently) and queues an `Accept` to the follower's inbox |
| `Undo` with an embedded `Follow` | removes the follower edge; an Undo for a follow that is already gone is still accepted |
| `Create` | stores the embedded `Note` in the remote-post store as a post |
| `Delete` | soft-deletes the matching remote activity/post, if this actor owns it; an unknown target is a successful no-op |
| `Announce` | stores the announced object as a boost, not as a standalone post |

Anything else — for example `Like` or `Update` — is refused with 400
`unsupported_activity`. Accepting an activity we cannot apply would tell a peer
its delivery succeeded when nothing happened.

## What a Follow does

```
POST /inbox (Follow, signed by bob)
  ├─ resolve https://remote.example/users/bob#main-key → RemoteActor
  ├─ verify signature
  ├─ followers.follow("alice", bob, bob-inbox)     (idempotent per actor)
  ├─ build Accept(local actor, embedded Follow)
  └─ queue federation.deliver → Accept → bob's inbox
```

The `Accept` is delivered by the same worker and the same signed-POST code as
a post. The reference assembly runs it synchronously in tests; a host runs it
from its cron or worker loop.

## What an Undo does

The embedded `Follow` is required: an Undo that names an activity id only
cannot be matched without storing every Follow activity we ever answered. The
actor inside the embedded Follow must be the signing actor, so nobody can undo
somebody else's follow.

## What a Create does

A `Create` must embed the object; a bare object id is not fetched. The actor
must be followed by at least one local account, and the object's
`attributedTo` (when present) must be the signing actor. The service stores:

- the activity id (the unique idempotence key),
- the object id and its author,
- the actor that signed the `Create`,
- the content, optional `summary` as content warning, and the published time.

The embedded `published` wins over the activity's `published`; if neither is
present the server clock is used. Timestamps must be RFC 3339; an invalid one
is `invalid_activity`, not a silently invented date.

After the row is stored, up to four image entries in the object's
`attachments` array are imported best-effort. The remote fetch is guarded
(http(s), public DNS/IP checks, redirect rechecks, timeout and body limit),
reuses the existing image validation and stores a local original plus
thumbnail. The remote post is linked to the successful local attachments; a
bad or missing image is logged and skipped while the post remains available.
See [media](media.md#remote-attachments-from-incoming-posts).

Remote rows are shared by all local followers. The home timeline joins the
row against the reader's `social_following` edge, so the same row is visible to
every local account that follows the actor and disappears for a reader who
unfollows. A per-user block is an additional `NOT EXISTS` filter on the same
query, so it hides the actor only for the reader who blocked it.

## What a Delete does

The target may be a URI or an embedded object with an `id`. The service first
looks for a stored activity with that id (a `Create` or `Announce`), then for a
live post with that object id. If the row belongs to another actor, the request
is 403 `actor_mismatch` and nothing changes. If the target is not stored, the
`Delete` is still accepted: the peer's intent is already satisfied.

Deletion is soft (`deleted_at`), like local posts. The row keeps the activity
id claimed and a later duplicate delivery cannot resurrect it.

## What an Announce does

An `Announce` is stored with `kind = boost` and its own activity id. The target
may be an embedded object or a URI. When the target object was already stored,
its content and content warning are used as a fallback for a sparse Announce.
An embedded object's image attachments are imported through the same guarded
path as a `Create`; a URI-only Announce carries no attachment metadata and
therefore does not fetch media. The home timeline returns one entry with
`kind: "boost"` and `boosted_by` set; it never invents a standalone post for
the same activity. Deleting the `Announce` activity id removes the boost.

## Replay and clocks

The activity id is claimed in an `ActivityLog` for 24 hours. A second delivery
of the same activity — even with a fresh, valid signature — is refused and
changes nothing. The remote-post table's primary key is also the activity id,
so a replay after the log window is still refused instead of creating a second
row.

The clock window for the signed `Date` is 300 seconds; see
[keys and clocks](../operations/keys-and-clocks.md).

## What is missing

- thread/context fetching;
- shared-inbox handling and delivery batching.

A bounded outbox catch-up for missed `Create`/`Announce` activities exists as
an explicit host operation; see [remote outbox backfill](remote-backfill.md).
It is not a full mirror: it does not fetch threads and it does not apply
`Delete` activities. It does import embedded-object images through the same
guarded `RemoteMediaService` as the live inbox, including for already-present
activities whose media was disabled or failed earlier. Scheduling and retrying
that explicit operation remain host concerns.
