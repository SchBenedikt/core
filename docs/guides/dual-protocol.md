# Two protocols, one local model

Aloha Social speaks ActivityPub (the Fediverse) and AT Protocol (Bluesky). The
short answer to "is this a new protocol?" is **no**.

The core keeps **one canonical local model** — a post, its author, its
visibility, its audience, its deletion — and translates that model to two wire
protocols. ActivityPub and AT Protocol remain the protocols peers speak. The
core adds no third wire format, no new server-to-server handshake and no new
identity namespace.

```
                 ┌──────────────────────────────┐
   HTTP / UI ───►│  Local model (canonical)     │
                 │  Post · Follow · Block · …   │
                 └───────────┬──────────────────┘
                             │
              ┌──────────────┴───────────────┐
              ▼                              ▼
   ┌─────────────────────┐        ┌─────────────────────┐
   │ ActivityPub adapter │        │ AT Protocol adapter │
   │ Note / Create       │        │ app.bsky.feed.post  │
   │ Follow / Announce   │        │ putRecord/delete    │
   └──────────┬──────────┘        └──────────┬──────────┘
              ▼                              ▼
     followers' inboxes                the author's PDS
     (Fediverse servers)               (Bluesky/AppView)
```

## What is shared

A local post exists once. Its visibility, content and deletion are decided
once. Both adapters read the same row:

- **Public text posts** fan out as a signed ActivityPub `Create(Note)` to the
  author's followers and, when the feature is enabled, as an
  `app.bsky.feed.post` record on the author's PDS.
- **Deletion** soft-deletes the local row, queues a signed `Delete` to the
  followers and, when a publication row exists, a `deleteRecord` to the PDS.
- **Visibility** is a local rule. AT Protocol publishing deliberately accepts
  only public posts, because a PDS record is public; followers-only and direct
  posts never leave the ActivityPub side (and direct posts stay local-only).
- **Idempotency** is local: a post id is stable, the ActivityPub activity ids
  are derived from it and the AT Protocol record key is a deterministic TID.
  A retry cannot create a second record.
- **Failure isolation**: a PDS outage never rolls back a local post or an
  ActivityPub delivery; an ActivityPub delivery never blocks a PDS write.

The stored mirror is readable through `GET /api/v1/posts/{id}/atproto` for the
author: `{"publication": null}` or the record URI, rkey and publication time.
It reads the local idempotency row, not the PDS, and stays available while
publishing is disabled.

## Mapping

| Capability | ActivityPub | AT Protocol | Implemented today |
| --- | --- | --- | --- |
| Identity | actor URL, `#main-key` | DID, handle, PDS | both exist, **not linked** |
| Public text post | `Create(Note)` | `app.bsky.feed.post` | both |
| Delete | `Delete` | `com.atproto.repo.deleteRecord` | both |
| Follow | `Follow` / `Accept` | `app.bsky.graph.follow` | ActivityPub |
| Block | `Block` / `Undo(Block)` | `app.bsky.graph.block` | ActivityPub |
| Boost | `Announce` | `app.bsky.feed.repost` | ActivityPub (local boost) |
| Like | `Like` | `app.bsky.feed.like` | local only |
| Reply | `in_reply_to` + `Create` | `reply` ref | local/ActivityPub |
| Media | attachment + public URL | blob + embed | ActivityPub |
| Profile | actor document | `app.bsky.actor.profile` | ActivityPub |
| Timeline | inbox + outbox | AppView | local/ActivityPub |
| Moderation | block, instance policy | labels, block lists | per-user block only |

"Local only" means the interaction is stored and projected locally but not
federated to either network. That is a boundary of this slice, not a second
protocol.

## What is deliberately not combined

- **Identity is not merged.** A local account is an ActivityPub actor and may
  additionally own a DID. The core does not claim that the actor and the DID
  are the same person; that proof belongs to account linking and the host.
- **Timelines are not merged.** The home timeline is built from local posts
  and remote ActivityPub content. Remote AT Protocol content is not ingested;
  that needs an AppView or firehose client.
- **Engagement is not mirrored.** A local favourite is not an AT Protocol
  `like`; an AT Protocol like is not a local favourite. Cross-network counts
  would be a bridge feature with its own semantics.
- **No new protocol.** There is no third envelope, no new identity, no new
  discovery mechanism. A peer still speaks ActivityPub or AT Protocol.

## Path to a real bridge

A bridge is more than dual publishing. The order that keeps the core honest:

1. **Account linking** (host): credential store, PDS selection, browser
   callback. The protocol client and OAuth flow already exist in the core.
2. **Identity proof**: publish and verify the link (for example an
   `alsoKnownAs`/`rel=me` proof) before claiming one person on both networks.
3. **Outbound mapping**: replies, media blobs, likes, reposts and profiles,
   one capability at a time, with protocol-specific limits (AT Protocol text
   is 300 graphemes; ActivityPub has no such global limit).
4. **Inbound AT Protocol**: AppView reads or a filtered firehose consumer,
   with the same storage, block and media guards as remote ActivityPub.
5. **Moderation**: decide whether a block applies to both networks and whether
   labels/blocks are imported; today only the ActivityPub side federates a
   block.

Until step 1 and 2 are done, "combined" means **dual publication from one
local model**, not a merged social graph. See
[AT Protocol publishing](atproto.md) for the current slice and
[ADR 0007](../adr/0007-two-protocols-one-model.md) for the decision.
