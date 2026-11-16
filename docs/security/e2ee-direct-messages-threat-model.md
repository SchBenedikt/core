# E2EE remote direct-message threat model

- Status: accepted for design; no implementation
- Date: 2026-11-16
- Related: issue #19, [ADR 0009](../adr/0009-e2ee-direct-messages.md),
  [ADR 0010](../adr/0010-e2ee-direct-messages-delivery.md)

This document is the security review for the remote direct-message design. It
does not add a message route, a crypto provider or a plaintext fallback. The
product boundary remains: **do not ship unencrypted remote direct messages.**

## Scope and assets

The scope is a one-to-one or small-group remote direct message whose content is
protected by MLS (RFC 9420). The local-only direct post behaviour is unchanged.

Assets, in order of sensitivity:

1. Message plaintext, attachments and message-level metadata embedded in the
   payload.
2. MLS group secrets, epoch secrets, private keys and key packages.
3. Device identity keys and the account-to-device binding.
4. Conversation membership and routing metadata visible to the server.
5. Delivery state, acknowledgements, retry state and timestamps.

## Trust boundaries

- The **client device** is trusted for its own plaintext and MLS state.
- The **PDS/server and any relay** are untrusted for content. They may store
  ciphertext, route it and see the minimum delivery metadata, but must not be
  able to decrypt it.
- **TLS** protects transport in transit but is not a substitute for E2EE: a
  server, proxy or compromised certificate path still sees what is sent to it.
- A **malicious group member** is inside the E2EE boundary by definition. E2EE
  protects against the server and network, not against a recipient who leaks.

## Adversaries

| ID | Adversary | Capability | Primary mitigation |
| --- | --- | --- | --- |
| A1 | Honest-but-curious or compromised server/relay | Reads database, logs, backups and stored blobs; can drop, delay or reorder delivery | Ciphertext-only storage; no plaintext logs/search/preview; MLS authentication; replay detection; delivery is not a confidentiality boundary |
| A2 | Network MITM | Intercepts or modifies TLS traffic, DNS or proxies | TLS plus MLS end-to-end authentication; never trust server-supplied plaintext or sender identity alone |
| A3 | Compromised client/device | Reads that device's plaintext and MLS state; can send as that device | Device-scoped credentials; no server escrow; revocation; MLS Update/Remove and Post-Compromise Security |
| A4 | Metadata observer | Sees participants, timing, message sizes, conversation graph, IP addresses and delivery state | Minimize metadata; document leakage; no claim of anonymity or metadata secrecy |
| A5 | Replay/reordering attacker | Repeats, drops, reorders or injects envelopes | Unique message IDs; epoch/order checks; replay cache; idempotent delivery; MLS rejects invalid epochs |
| A6 | Key-compromise attacker | Obtains a device key, group state or old epoch secret | Forward Secrecy for past epochs; Post-Compromise Security after an Update/Commit; prompt device revocation; no server escrow |
| A7 | Malicious group member | Has legitimate plaintext and can copy it | Out of scope for E2EE; abuse/reporting and recipient policy are separate product controls |
| A8 | Key-package substitution / malicious device registration | Registers a device or replaces a key package | Account-signed device binding; one-time key packages; consume atomically; verify credential and signature; visible device list and revocation |
| A9 | Stale or rolled-back client | Uses an old epoch, re-adds a removed device or replays a Welcome | Epoch monotonicity, membership checks, consumed key packages and server-side device status |

## Security objectives

- **Confidentiality:** the server, relay and network cannot read message content,
  attachments or the plaintext inside an MLS application message.
- **Integrity and authenticity:** recipients verify the MLS sender credential and
  signature; the server cannot substitute content without detection.
- **Forward Secrecy:** compromise of current key material does not reveal past
  epochs.
- **Post-Compromise Security:** a later MLS Update/Commit restores secrecy after
  a device compromise, subject to the attacker no longer controlling the device.
- **Device authenticity:** every device is bound to an account through a signed
  registration and has its own MLS credential; one device cannot silently
  impersonate another.
- **Membership consistency:** only current group members can decrypt; removed
  devices stop receiving envelopes and cannot be re-added without a fresh
  key package.
- **Replay and reordering resistance:** duplicate, stale-epoch and out-of-order
  envelopes are detected; delivery is idempotent.
- **No plaintext at rest or in observability:** no plaintext bodies, previews,
  search indexes, push payloads or logs.

## What E2EE does not protect

- **Metadata:** participants, conversation graph, message timing, sizes,
  delivery/read state, IP addresses and traffic patterns remain visible to the
  server or a network observer. Sealed-sender or traffic-shaping work is not
  part of this design.
- **Availability:** the server can refuse, delay, drop or permanently delete
  ciphertext; E2EE does not provide censorship resistance or guaranteed
  delivery.
- **Endpoint compromise:** malware, a compromised browser or a stolen unlocked
  device can read plaintext. E2EE cannot protect a compromised endpoint.
- **Malicious recipients:** a member can copy or publish plaintext. E2EE is not
  DRM and does not prevent recipient leakage.
- **Account recovery and key escrow:** there is no server-side recovery key. A
  lost device key can make old ciphertext unrecoverable; recovery is a client
  key-management feature, not a server backdoor.
- **Content policy and abuse handling:** the server cannot inspect plaintext for
  spam, illegal content or legal holds. Reporting must be recipient-initiated
  and must not create a plaintext archive.
- **Backups of endpoint state:** a backed-up plaintext client database is
  outside the E2EE boundary unless the client encrypts it separately.

## Accepted residual risks

These risks are accepted for the design because removing them would require a
different product, client platform work or a much larger metadata-resistance
programme:

1. **Server-visible metadata** (participants, timing, sizes, delivery state) is
   accepted and minimized; it is not treated as secret.
2. **Traffic analysis** can infer conversation activity even when content is
   encrypted. No padding or cover traffic is promised in v1.
3. **Denial of service** by the server or network is accepted; clients surface a
   delivery failure and never silently downgrade to plaintext.
4. **Compromised endpoints** can read their own plaintext; device revocation and
   user-visible device lists limit persistence but cannot undo a compromise.
5. **Malicious members** can leak content; abuse handling is a separate policy
   and reporting flow, not a server decryption capability.
6. **Lost device keys** can make ciphertext unrecoverable. There is no server
   escrow; recovery needs a client-side multi-device or backup design.
7. **Client implementation defects** remain possible; interoperability vectors,
   adversarial tests and an independent review are release gates.

## Required controls for a future implementation

- MLS RFC 9420 with a vetted implementation; no handwritten MLS, HPKE or key
  schedule in this repository.
- A ciphertext envelope with an explicit version and ciphersuite; unknown
  versions or suites are refused, never downgraded.
- Device registration authenticated by the account and signed by the device;
  one-time key packages consumed atomically.
- Server storage of ciphertext, public device metadata and delivery state only.
- Replay cache, idempotent message IDs, epoch validation and bounded retries.
- No shared-inbox fan-out, public outbox, search, profile, preview or push
  plaintext for direct content.
- A disabled-by-default feature gate and a migration that never imports
  plaintext into the E2EE store.
- Adversarial tests from the list below before enabling the feature.

## Security gates

The implementation is not releasable until these tests and the independent
review exist:

- BCC/CC and recipient-expansion leakage across envelope and API responses.
- Unauthorized device reads, cross-conversation reads and removed-device reads.
- Outbox/search/profile and notification-preview disclosure.
- Signed replay, reordered epoch, stale Welcome and replayed key package.
- Key-package substitution, revoked device and missing root/deleted account.
- Multi-device add/remove, forward secrecy and post-compromise recovery.
- Delivery retry, duplicate acknowledgement, dropped envelope and stable cursor.
- Log/database/backup inspection proving no plaintext message body is stored.

The design-level decisions are in
[ADR 0010](../adr/0010-e2ee-direct-messages-delivery.md). Issue #19 remains open
for implementation, interoperability vectors, the tests above and the
independent security review.
