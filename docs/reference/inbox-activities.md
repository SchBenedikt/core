# Inbox activities and refusals

The contract of `POST /inbox`. The flow is described in
[inbound federation](../guides/inbound-federation.md); this page is the table
you check while debugging a peer.

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
signed `Digest` is refused.

## Activity types

| `type` | Required fields | Effect |
| --- | --- | --- |
| `Follow` | `id`, `actor`, `object` = local actor URL | Creates the follower edge; queues `Accept` |
| `Undo` | `id`, `actor`, `object` = embedded `Follow` | Removes the follower edge |

For `Undo`, the embedded Follow must satisfy the same rules as a Follow and
its `actor` must equal the signing actor.

## Refusals

| Status | Code | When |
| --- | --- | --- |
| 400 | `invalid_json` | Body is not valid JSON or not an object |
| 400 | `invalid_activity` | `id`, `type` or `actor` missing; `object` missing or of the wrong shape |
| 400 | `unsupported_activity` | Type other than Follow/Undo(Follow), or an Undo without an embedded Follow |
| 400 | `replayed_activity` | This activity id was already accepted |
| 401 | `signature_required` | No `Signature` header |
| 401 | `invalid_signature` | Malformed header, missing signed header, digest mismatch, wrong key or unsupported algorithm |
| 401 | `signature_expired` | `Date` outside the 300-second window |
| 403 | `actor_mismatch` | Valid signature, but the activity claims another actor (or an Undo of another actor's Follow) |
| 404 | `unknown_actor` | The `keyid` does not resolve to a usable actor |
| 404 | `unknown_object` | The Follow does not address an actor on this instance |
| 503 | `inbox_not_configured` | No actor resolver, HTTP client or signing key configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing while answering |

`403` is used only when the signature is valid and the request is a clear
protocol violation by an authenticated actor. Unknown keys are `404`, not
`403`, so probing cannot distinguish "no such actor" from "cannot fetch".

## Success

```http
HTTP/1.1 202 Accepted
Content-Type: application/json

{"status": "accepted"}
```

`202` means the activity was applied, not merely queued: the follower edge is
already in the repository. The `Accept` delivery is asynchronous.

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
