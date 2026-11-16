# ADR 0009 — Remote direct messages require end-to-end encryption

- Status: accepted
- Date: 2026-11-15
- Related: issue #19, [ADR 0007](0007-two-protocols-one-model.md),
  [ADR 0008](0008-native-pds.md),
  [ADR 0010](0010-e2ee-direct-messages-delivery.md),
  [threat model](../security/e2ee-direct-messages-threat-model.md)

## Context

The core supports **local** direct posts: `Post::recipientIds` names local
accounts, `PostService::recipientsFor()` validates them, and the ActivityPub
factory can address them. There is no remote recipient port, no remote direct
delivery and no persistent conversation projection. The roadmap explicitly
keeps remote direct delivery out of the local slice.

Issue #19 asks for an explicit design before copying the local post path to
remote recipients. That path would be unsafe by default: an ActivityPub
`Create(Note)` delivered to a recipient inbox is visible to the receiving
server, and a server-to-server direct message is not end-to-end encrypted.

The product decision is: **do not ship unencrypted remote direct messages.
Remote DMs wait for an E2EE/MLS design and threat-model review.**

## Decision

1. Remote direct messaging is **blocked** until the E2EE design is implemented
   and reviewed. The existing local-only direct post behavior stays unchanged.
2. The target protocol is **MLS (RFC 9420)** with asynchronous key packages.
   The server may route ciphertext and store conversation metadata, but it must
   not be able to read message content.
3. The server stores ciphertext envelopes, sender/recipient routing metadata,
   timestamps and delivery state. It does not index plaintext, generate content
   previews, run content search over messages or put message bodies in logs.
4. MLS group state and private keys stay on the client. A multi-device account
   needs an explicit key-package/device model; a server-side escrow key is not
   part of this design.
5. AT Protocol Chat, if added later, is a separate protocol adapter. It is not
   the same wire format as an ActivityPub DM and must not be silently mapped
   onto it.
6. The ActivityPub transport, if used, carries opaque MLS ciphertext and the
   minimum routing data. A future implementation needs its own recipient port,
   signed targeted delivery, replay protection, blocked-actor checks and
   durable conversation/read markers.
7. The threat model, ciphersuite, envelope format, device/key-package model and
   delivery flow are accepted in
   [ADR 0010](0010-e2ee-direct-messages-delivery.md) and the
   [threat model](../security/e2ee-direct-messages-threat-model.md). They close
   the design questions; no plaintext or stub implementation is permitted while
   the remaining implementation work is pending.

## Threat model

The detailed threat model is
[`docs/security/e2ee-direct-messages-threat-model.md`](../security/e2ee-direct-messages-threat-model.md).
It defines the adversaries, security objectives, non-goals and accepted
residual risks. The design and review must cover:

- a malicious or compromised server reading, modifying, replaying or dropping
  messages;
- metadata leakage: participants, timing, message sizes, delivery state and
  conversation graph;
- device compromise, key-package substitution, group membership changes and
  forward secrecy/backward secrecy;
- multi-device synchronization, backup and account recovery without a server
  escrow key;
- unauthorized recipient expansion, BCC/CC leakage, outbox/search/profile
  disclosure and revoked access;
- spam, abuse reporting and legal holds without turning the server into a
  plaintext reader;
- missing roots, deleted accounts and stable conversation cursors.

## Delivery phases

1. **This ADR:** record the no-plaintext boundary and the MLS target.
2. **Threat model and ciphersuite decision:** **complete** in
   [ADR 0010](0010-e2ee-direct-messages-delivery.md) and the
   [threat model](../security/e2ee-direct-messages-threat-model.md): MLS
   ciphersuite `0x0001`, device/key-package model, envelope v1, metadata
   minimization and abuse/reporting boundary.
3. **Client MLS provider:** a vetted MLS implementation behind a client-side
   provider, with RFC 9420 test vectors and refusal behavior. The server does
   not implement MLS crypto.
4. **Envelope and conversation store:** ciphertext-only persistence, delivery
   state, read/dismiss markers and stable cursors.
5. **Transport:** targeted signed delivery, replay protection, retries and
   acknowledgements. Never use shared-inbox fan-out, public outbox, search or
   profile routes for direct content.
6. **Client integration and audit:** multi-device tests, adversarial tests and
   an independent security review before enabling the feature.

## Consequences

**Good**

- The product does not make a false privacy promise.
- The server cannot become a plaintext message archive by accident.
- Local direct posts remain available while the remote design is reviewed.

**Costs**

- Remote DMs stay unavailable for longer; #19 remains open until the crypto,
  key-management and transport work is delivered.
- MLS adds client complexity, key-package discovery and multi-device state.
- Metadata minimization and abuse handling need explicit product decisions.

## Implementation status

As of 2026-11-16, the design review is complete: the threat model,
ciphersuite and delivery/envelope design are in
[ADR 0010](0010-e2ee-direct-messages-delivery.md) and the
[threat model](../security/e2ee-direct-messages-threat-model.md). No remote DM,
MLS provider, ciphertext envelope or conversation store exists. This ADR remains
a security boundary decision, not an implementation. Issue #19 stays open only
for the implementation, interoperability vectors, adversarial tests and the
independent security review.
