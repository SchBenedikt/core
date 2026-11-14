# Inbox activities and refusals

The contract of `POST /inbox` and `POST /inbox/shared`. The flow is described in
[inbound federation](../guides/inbound-federation.md); this page is the table
you check while debugging a peer. Both routes apply the same checks; only the
path covered by the signature differs.

## Request

```http
POST /inbox HTTP/1.1
Host: social.example
Date: Wed, 11 Nov 2026 10:00:00 GMT
Digest: SHA-256=…
Signature: keyId="https://remote.example/users/bob#main-key",algorithm="rsa-sha256",
           headers="(request-target) host date digest",signature="…"
Content-Type: application/activity+json

{"@context":"https://www.w3.org/ns/activitystreams",
 "id":"https://remote.example/activities/follow-1",
 "type":"Follow",
 "actor":"https://remote.example/users/bob",
 "object":"https://social.example/@alice"}
```

The signature covers `(request-target) host date digest`. A body without a
signed `Digest` is refused. For the shared inbox, use
`(request-target): post /inbox/shared` and send the request to that path.

## Activity types

| `type` | Required fields | Effect |
| --- | --- | --- |
| `Follow` | `id`, `actor`, `object` = local actor URL | Creates the follower edge when the optional `FollowPolicy` accepts it; queues `Accept` |
| `Accept` | `id`, `actor`, `object` = embedded outgoing `Follow` or its id | Moves the matching outgoing pending follow to accepted |
| `Reject` | `id`, `actor`, `object` = embedded outgoing `Follow` or its id | Moves the matching outgoing pending follow to rejected |
| `Undo` | `id`, `actor`, `object` = embedded `Follow`, or an `Announce` embedded/by id | Removes the follower edge or soft-deletes the stored boost |
| `Create` | `id`, `actor`, embedded `object` with `id` and `content` | Stores a remote post |
| `Update` | `id`, `actor`, embedded `object` with `id`, `content` and optional `updated` | Replaces the stored post with the author's newer version, or holds it until the `Create` arrives |
| `Delete` | `id`, `actor`, `object` = URI or embedded object with an `id` | Soft-deletes the matching remote post/activity |
| `Announce` | `id`, `actor`, `object` = URI or embedded object with an `id` | Stores a remote boost |

For `Undo(Follow)`, the embedded Follow must satisfy the same rules as a Follow
and its `actor` must equal the signing actor. For `Undo(Announce)`, the target
must be a stored boost owned by the signing actor; an absent target is a
successful no-op.

For `Accept`/`Reject`, the signing actor must be the actor that the local
account followed. The object is either the exact Follow activity id stored on
the pending row or an embedded Follow whose `actor` is the local actor and
whose `object` is the followed actor. A duplicate, a wrong embedded Follow, a
foreign signer or a response after an Unfollow changes nothing; the duplicate
and late forms are successful no-ops, while a signature-valid foreign signer
is `403 actor_mismatch`.

For `Create`, `Update`, `Delete` and `Announce`, at least one local account must follow
the signing actor. The object's `attributedTo` (when present) must also be the
signing actor for a `Create`; an embedded Announce author is recorded when
present. `Delete` only accepts a target owned by the signing actor. A remote
object is never fetched from the network.

`Create` and `Announce` timestamps must be RFC 3339. A missing timestamp uses
the activity timestamp and then the server clock. An invalid timestamp is
`invalid_activity`.

## Refusals

| Status | Code | When |
| --- | --- | --- |
| 400 | `invalid_json` | Body is not valid JSON or not an object |
| 400 | `invalid_activity` | `id`, `type` or `actor` missing; the object, content, timestamp or content warning has the wrong shape |
| 400 | `unsupported_activity` | A type other than Follow/Accept/Reject/Undo/Create/Update/Delete/Announce |
| 400 | `replayed_activity` | This non-Accept/Reject activity id was already accepted |
| 401 | `signature_required` | No `Signature` header |
| 401 | `invalid_signature` | Malformed header, missing signed header, digest mismatch, wrong key or unsupported algorithm |
| 401 | `signature_expired` | `Date` outside the 300-second window |
| 403 | `actor_mismatch` | Valid signature, but the activity claims another actor, a created or updated object belongs to another actor, or a Delete/Update targets another actor's row |
| 403 | `blocked_actor` | The host's blocked-actor policy refuses this actor |
| 403 | `not_followed` | `Create`/`Update`/`Delete`/`Announce`/`Undo(Announce)` from an actor no local account follows |
| 403 | `follow_not_accepted` | A valid Follow is refused by the host's `FollowPolicy` |
| 404 | `unknown_actor` | The `keyid` does not resolve to a usable actor |
| 404 | `unknown_object` | A Follow does not address an actor on this instance |
| 503 | `inbox_not_configured` | No actor resolver, HTTP client or signing key configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing while answering |

`403` is used only when the signature is valid and the request is a clear
protocol or policy violation by an authenticated actor. Unknown keys are `404`,
not `403`, so probing cannot distinguish "no such actor" from "cannot fetch".

## Success

```http
HTTP/1.1 202 Accepted
Content-Type: application/json

{"status": "accepted"}
```

`202` means the activity was applied, not merely queued: a Follow's edge or a
Create/Announce's remote-post row is already in the repository. A `Delete` for
an unknown object is also accepted, because the peer's intended state is
already true. An `Accept`/`Reject` for an unknown or already removed outgoing
Follow is likewise a successful no-op. A duplicate `Accept`/`Reject` with the
same activity id is still `202`, because re-applying a conditional transition
cannot change the row twice. Outbound Follow/Undo/Accept delivery remains
asynchronous in the queue.

## Example: Create

```json
{"@context": "https://www.w3.org/ns/activitystreams",
 "id": "https://remote.example/activities/create-1",
 "type": "Create",
 "actor": "https://remote.example/users/bob",
 "published": "2026-11-11T10:01:00Z",
 "object": {
   "id": "https://remote.example/notes/1",
   "type": "Note",
   "attributedTo": "https://remote.example/users/bob",
   "content": "hello from the peer",
   "summary": "optional content warning",
   "published": "2026-11-11T10:01:00Z"
 }}
```

The activity id is the deduplication key. The object id is stored separately
so a later `Delete` of the Note can find exactly that row; an `Announce` with
its own activity id is stored as a boost of the same object.

## Answering a Follow

The queued `Accept` embeds the original Follow unchanged:

```json
{"@context": "https://www.w3.org/ns/activitystreams",
 "id": "https://social.example/api/v1/followers/…/accept",
 "type": "Accept",
 "actor": "https://social.example/@alice",
 "object": {"id": "https://remote.example/activities/follow-1", "type": "Follow", "…": "…"},
 "to": ["https://remote.example/users/bob"]}
```

It is signed with the local actor's key and delivered to the inbox from the
resolved actor document, not to an inbox taken from the request body.
