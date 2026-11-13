# ADR 0007 — Two wire protocols, one local model

- Status: accepted
- Date: 2026-11-13

## Context

The core already speaks ActivityPub for posts, follows, blocks, delivery and
discovery, and it publishes public text posts to an AT Protocol PDS. Both are
implemented over the same local post, follow and media rows. The next product
question is whether this combination should become a protocol of its own, or
whether the core should merge the two networks.

Both are bad ideas unless the product deliberately becomes a bridge. A third
wire format would need new discovery, identity and server-to-server semantics
that neither network would understand. A merged graph would claim an identity
relationship the core cannot prove.

## Decision

**The local model is canonical; ActivityPub and AT Protocol are adapters.** The
core translates the same local post to both where the semantics allow it. It
does not define a third wire protocol, a new identity namespace or a merged
federation domain.

Concretely:

- One local row per post, one visibility rule, one deletion path.
- ActivityPub and AT Protocol failures are independent.
- Public text posts may be published to both; followers-only and direct posts
  never go to a PDS.
- The stored AT Protocol mirror is readable by its author through
  `GET /api/v1/posts/{id}/atproto`, without contacting the PDS.
- Identity linking, credential storage and PDS selection remain host work; the
  core does not claim that an actor URL and a DID are the same account.
- Remote AT Protocol timelines, engagement mirroring and inbound firehose
  ingestion are bridge features, not part of this decision.

The mapping and the path to a real bridge are in
[two protocols, one model](../guides/dual-protocol.md).

## Consequences

**Good**

- One rule set for validation, visibility, deletion and moderation.
- Either network can fail without corrupting the local post or the other
  delivery.
- No lock-in: a host that enables only one adapter still works, and a future
  bridge adds capabilities rather than replacing the model.

**Costs**

- There is no merged social graph and no cross-network engagement count.
- The same content can exist twice (one ActivityPub object, one PDS record)
  with independent lifecycles. Deletion ordering is handled by two idempotent
  jobs, not by a distributed transaction.
- Each adapter needs its own limits and failure semantics; AT Protocol is
  public-only and text-only in this slice.
- Identity verification remains unsolved until account linking exists.

## Rejected alternatives

- **A new protocol that wraps both.** It would need adoption by peers that
  speak ActivityPub or AT Protocol, and it would duplicate two mature
  ecosystems.
- **A single merged timeline in the core.** The core has no AppView and no
  firehose; claiming a merged timeline without one would be a local illusion.
- **Mapping likes, reposts and replies to both networks now.** Those need
  account linking, protocol-specific record shapes and moderation rules; doing
  them before identity would create unverifiable cross-network claims.

## Follow-ups

- Account linking and an identity proof before any "same person" claim.
- Outbound replies, media blobs and engagement mapping, one capability at a
  time.
- Inbound AT Protocol through an AppView or a filtered firehose, behind the
  same guards as remote ActivityPub.
