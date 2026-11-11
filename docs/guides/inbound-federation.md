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
9. **Activity id not seen before?** — 400 `replayed_activity`.
10. **Type supported?** — 400 `unsupported_activity`.

A refusal releases the replay claim, so a peer that fixes its document can
deliver the same activity again.

## Supported activities

| Type | Effect |
| --- | --- |
| `Follow` | stores the follower edge (idempotently) and queues an `Accept` to the follower's inbox |
| `Undo` with an embedded `Follow` | removes the follower edge; an Undo for a follow that is already gone is still accepted |

Anything else — `Create`, `Delete`, `Announce`, an Undo of something that is
not a Follow, an Undo that only references an activity by id — is refused with
400 `unsupported_activity`. Accepting an activity we cannot apply would tell a
peer its delivery succeeded when nothing happened.

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

## Replay and clocks

The activity id is claimed in an `ActivityLog` for 24 hours. A second delivery
of the same activity — even with a fresh, valid signature — is refused and
changes nothing. The clock window for the signed `Date` is 300 seconds; see
[keys and clocks](../operations/keys-and-clocks.md).

## What is missing

- inbound `Create`/`Delete`/`Announce` and fetching remote posts;
- serving this instance's actor document, `followers` collection and WebFinger;
- a key cache (the core fetches on every request when using
  `HttpActorResolver`);
- a policy layer for approving Follows instead of accepting every valid one.
