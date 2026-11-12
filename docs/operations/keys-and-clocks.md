# Keys, clocks and replay

Operational notes for running the inbox. The behaviour itself is in
[inbound federation](../guides/inbound-federation.md).

## Publishing the key

`GET /@{handle}` publishes this instance's public key under the exact key id
that inbound verification resolves (`{actor}#main-key`). A peer can therefore
fetch the document, verify our outbound signatures, and use the same identity
when it addresses the inbox. See the
[actor document reference](../reference/actor-document.md).

## Key resolution

`HttpActorResolver` turns a `keyid` into an actor:

1. drop the fragment: `…/users/bob#main-key` → `…/users/bob`;
2. fetch the actor document with `Accept: application/activity+json`;
3. require the document `id` to equal that actor URL;
4. require `publicKey.id` to equal the **full** key id from the signature;
5. use `publicKey.publicKeyPem` and `inbox`.

Step 4 is the important one: a document may list several keys, and the request
must be signed by the exact key it names. Accepting "any key in the document"
would let a rotation key sign for an old identity.

### Bounded actor cache

`CachingActorResolver` wraps `HttpActorResolver` and remembers the already
validated actor by the **full key id**:

```php
$resolver = new CachingActorResolver(
	new HttpActorResolver($http),
	$clock,
	ttlSeconds: 300,   // default
	maxEntries: 512,   // default
);
```

A hit avoids the HTTP round trip; a miss still performs every identity check.
Failures are not cached, so a transient network error does not keep an actor
unknown. The default TTL is deliberately short: a revoked or rotated key
remains usable until the entry expires, and the cache is bounded so a busy
inbox cannot grow it without limit. Call `clear()` after an emergency key
rotation that must take effect immediately.

A host with its own cache may use the same rules:

- key by the full key id, not by actor URL;
- re-apply the identity checks after a cache hit and expire the entry;
- never cache a failed resolution.

A cache that never expires keeps a compromised key alive.

## Clock window

A signature is accepted when its signed `Date` is within **300 seconds** of the
server clock (`InboxService::MAX_SKEW_SECONDS`). Peers with drifting clocks
are refused with `401 signature_expired`.

Operationally:

- run NTP on the server; a drifting clock refuses valid peers;
- do not widen the window to "make it work" — a wide window is a replay
  window;
- when debugging a refusal, compare the peer's `Date` header with `date -u`.

## Replay window

Accepted activity ids are remembered for **24 hours**
(`PdoActivityLog::RETENTION_SECONDS`, the same value as
`InMemoryActivityLog::RETENTION_SECONDS`). Within that window a replay is
refused with `400 replayed_activity` even with a fresh signature.

The in-memory implementation is per process. A production host should pass
`PdoActivityLog` so several workers and restarts share the same
`social_activity_log` table; otherwise a replay can land on another process.

## Key rotation

Rotating the instance key is a host operation:

1. publish the new public key in the actor document;
2. keep the old key verifiable for at least one clock window plus delivery
   retry time;
3. switch signing over;
4. remove the old key after the retry horizon (the queue retries for up to
   five attempts, at most an hour of backoff).

Outbound requests that were queued before the switch are signed with the key
id stored in the job (`key_id`), so the old key must remain available until
those jobs are drained.

## What must never be logged

- the private key or a sealed form of it;
- the full `Signature` header of a failed request (it is a valid credential
  within its window);
- request bodies of private activities.

The rejection code is enough to diagnose a peer.
