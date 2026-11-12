# Shared inboxes and delivery batching

A server can advertise one inbox that accepts activities for all of its actors.
Delivering a post once to that shared inbox replaces one delivery per follower
on the same server. Aloha Social implements both halves: it advertises its own
shared inbox in every actor document, accepts signed activities there, and uses
a peer's shared inbox for outbound posts when the peer advertised one.

The individual inbox remains authoritative for actor-specific activities. A
`Follow` and its `Undo` go to the individual inbox because the peer has to match
them to one actor. `Accept` is sent there too.

## Discovery

`GET /@{handle}` now contains:

```json
{
  "inbox": "https://social.example/inbox",
  "endpoints": {
    "sharedInbox": "https://social.example/inbox/shared"
  }
}
```

`endpoints.sharedInbox` is the standard ActivityPub location for this value.
`HttpActorResolver` reads it while resolving a signing key and stores it on the
`RemoteActor`; a missing `endpoints` object is normal and keeps the individual
inbox. A malformed value is ignored rather than failing the actor resolution:
the individual inbox is still sufficient, so a peer with a bad optional field
does not become unreachable.

The advertised URL is built by `ActivityFactory::sharedInboxId()` and points at
the `inbox.shared` route in the manifest, so the document cannot drift from the
route that actually serves it.

## Inbound

Both `POST /inbox` and `POST /inbox/shared` use the same `InboxHandler` and
`InboxService`. They apply exactly the same checks:

1. the HTTP signature is verified over the actual request path;
2. the signing key is resolved to its actor;
3. the activity actor must own the key;
4. host block and follow policies apply;
5. the replay guard claims the activity id;
6. only supported activity types change state.

The shared route is not a weaker trust boundary. It is not authenticated by a
session and it does not accept unsigned requests. A peer signing a request to
`/inbox/shared` must include that path in `(request-target)`; a signature made
for `/inbox` cannot be replayed against it.

When a signed `Follow` arrives at either route, the actor's advertised
`endpoints.sharedInbox` is stored with the follower edge. The `Accept` is still
queued to the actor's individual inbox.

## Outbound batching

`social_follower` has a nullable `shared_inbox_url` next to `inbox_url`.
Local-to-local Mastodon follows are stored with `is_local = 1` and are
filtered out before this query, so they never produce a delivery job or a
signed `Follow`. `FollowerRepository::inboxesFor()` returns:

```
COALESCE(shared_inbox_url, inbox_url)
```

and de-duplicates the result. Two followers on `a.example` with the same
advertised shared inbox therefore produce one delivery job, while a follower
without a shared inbox keeps using its individual inbox. The job payload is
still one inbox and one activity, so the worker, signature, retry and dead-letter
behaviour are unchanged.

```
follow (bob@a.example)    ──► social_follower.shared_inbox_url = a.example/inbox/shared
follow (carol@a.example)  ──► same shared inbox
post (alice)              ──► inboxesFor("alice")
                              └─ one job → https://a.example/inbox/shared
```

The stored individual inbox is not replaced. It remains available for Follow,
Undo and Accept, and is the fallback for any follower row whose
`shared_inbox_url` is `NULL`.

Delivery does not retry a failed shared inbox against the individual inbox
automatically. The job contains the target chosen at write time, and switching
targets midway would send the same activity twice to a server that may have
accepted the first attempt. A later Follow refresh updates the stored shared
inbox; an operator that needs a server-wide fallback can clear the column and
let the next delivery use the individual inboxes.

## Migration

Existing installations must add the nullable column before deploying this
version:

```sql
ALTER TABLE social_follower ADD COLUMN shared_inbox_url VARCHAR(512) NULL;
ALTER TABLE social_follower ADD COLUMN is_local SMALLINT NOT NULL DEFAULT 0;
```

The `is_local` column defaults existing rows to remote (`0`), so the local
follow feature needs no backfill. The DDL is also part of
`FollowerSchema::statements()` for a fresh install. A
row with `NULL` behaves exactly as before, so the migration does not require a
backfill and does not rewrite existing inbox values. `PdoFollowerRepository`
reads and writes the column on insert and on a repeated Follow.

A host that creates `social_follower` from an earlier schema but does not add
the column will fail on the first follower read or write; the schema change is
part of the same release as the code. There is no default value to invent and no
data cleanup job: removing a follower removes its row and its shared-inbox URL.

## Behaviour when disabled

There is no separate switch. Inbound shared-inbox handling is enabled exactly
when the normal inbox is configured: an actor resolver, an HTTP client and a
signing key. Without them both inbox routes answer `503 inbox_not_configured`.
Outbound batching needs no switch: when a peer does not advertise a shared
inbox, or the stored value is `NULL`, the existing individual-inbox path is
used. A peer that ignores the advertised endpoint can still deliver at
`/inbox`; the actor document always advertises the shared route in this version
because it is part of the protocol surface.
