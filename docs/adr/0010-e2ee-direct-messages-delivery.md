# ADR 0010 — E2EE direct-message ciphersuite and delivery design

- Status: accepted for design; no implementation
- Date: 2026-11-16
- Supersedes: the open design questions in ADR 0009
- Related: issue #19,
  [ADR 0009](0009-e2ee-direct-messages.md),
  [threat model](../security/e2ee-direct-messages-threat-model.md)

## Context

ADR 0009 blocks all remote direct messages until an E2EE design and threat-model
review exist. It leaves three decisions open: the threat model, the ciphersuite
and the delivery design. Those decisions are made here so a later change can
implement a specified protocol instead of reopening the security debate.

This ADR adds documentation only. It does not add a message route, database
schema, crypto provider or production code. The boundary in ADR 0009 remains:
**do not ship unencrypted remote direct messages.**

## Decision

### Protocol and ciphersuite

Remote direct messages use **MLS (RFC 9420)** with the MLS 1.0 mandatory-to-
implement ciphersuite:

| Component | Value | Identifier |
| --- | --- | --- |
| MLS ciphersuite | `MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519` | `0x0001` |
| HPKE KEM | X25519 | `0x0020` |
| HPKE KDF | HKDF-SHA256 | `0x0001` |
| HPKE AEAD | AES-128-GCM | `0x0001` |
| MLS hash | SHA-256 | `0x0001` |
| Signature | Ed25519 | `0x0807` |

Version 1 accepts exactly this suite. A future suite requires a new envelope
version and interoperability tests; the server never negotiates a weaker suite.
There is no plaintext, “best effort” or unsigned mode.

### Why this suite

- It is the RFC 9420 mandatory-to-implement suite, so independent clients have a
  single interoperability baseline.
- X25519 and Ed25519 are widely available in reviewed client libraries and are
  small enough for mobile and browser use.
- AES-128-GCM is authenticated and hardware-accelerated on common platforms.
- SHA-256 avoids a second hash dependency in the handshake and key schedule.
- A P-256 suite (`0x0002`) is not enabled in v1. It is a later option for
  platforms whose crypto policy forbids X25519; adding it does not require a
  server change but does require new vectors and a version bump.

### Credentials and identity

- Each device has a long-term Ed25519 identity key and an MLS `BasicCredential`
  whose identity is the device’s `did:key`-style public identifier.
- The device key is bound to the account DID by a signed registration
  challenge. The server stores the public key, the account DID, the device
  identifier and a status; it never stores the private key.
- The device list is visible to the account owner. A new device requires an
  authenticated account session plus possession of the new device key.
- MLS verifies leaf signatures. The server’s role is to attest the account/device
  binding, not to be a certificate authority for message content.

### Why not self-made crypto

MLS combines a ratchet tree, HPKE, a key schedule, epoch transitions,
Welcome/Commit processing and transcript authentication. A partial or
“simplified” implementation commonly loses forward secrecy, post-compromise
security or membership consistency without failing an obvious test. A custom
scheme would also have no independent interoperability evidence. The project
therefore uses a vetted MLS implementation and test vectors; it does not design
its own primitives, double ratchet, envelope encryption or key schedule.

### Why not an unencrypted interim

An unencrypted remote DM would create exactly the server-readable archive and
metadata surface this design exists to avoid, and users would reasonably read
“direct” as private. A stub that accepts plaintext, or a route that returns
“not implemented” while storing content, is also forbidden. Until the complete
encrypted path and its review exist, remote DMs stay unavailable and local-only
direct posts keep their current semantics.

## Library and primitive availability

Checked on 2026-11-16 against the PHP stack used by this repository, the
[RFC 9420 text](https://www.rfc-editor.org/rfc/rfc9420.html) and the public
Composer manifests and READMEs:

| Candidate | Result | Decision |
| --- | --- | --- |
| [`discord-php-helpers/rfc9420`](https://github.com/discord-php/RFC9420) | Interface-only RFC 9420 definitions and registry enums. The README says no concrete cryptographic primitives are included; its “Basic” adapters are explicitly for tests/examples and one signature verifier is a stub. | Not usable for production. |
| [`discord-php/mls`](https://github.com/discord-php/MLS) | One-commit scaffold whose Composer manifest requires `ext-sodium` and `ext-openssl`. README: HPKE does not implement RFC 9180 and the OpenSSL HPKE adapter does not encrypt. | Not usable for production. |
| PHP runtime in this workspace | PHP 8.4.8 with OpenSSL 3.5.0; `ext-sodium` is not loaded. OpenSSL provides AEAD/hash/signature primitives but not an MLS group implementation or RFC 9180 HPKE API. | Not sufficient by itself; no PHP MLS implementation is added to this repository. |
| Established Rust MLS implementations: [OpenMLS](https://github.com/openmls/openmls) and [mls-rs](https://github.com/awslabs/mls-rs) | Independently developed implementations with RFC 9420 test-vector work. They are client-side libraries, not PHP server dependencies. | Preferred implementation basis for a client SDK, subject to the security review. |

The practical strategy is therefore:

1. **The PDS/server never performs MLS operations.** It validates envelope
   metadata, stores opaque ciphertext and routes it to devices. Compromise of
   the server must not yield group keys.
2. **Clients use a vetted MLS implementation** (OpenMLS, mls-rs or a platform SDK
   built on one) behind a client-side provider interface. The implementation
   must pass the RFC 9420 test vectors and the project’s adversarial tests.
3. **A PHP client, if one is ever built, binds to a vetted library** (for
   example through a reviewed FFI/native binding) instead of implementing MLS
   in PHP. Interface-only or scaffold packages are rejected.
4. **No new Composer dependency is added by this ADR.** Adding one is part of the
   implementation change, with its own review and supply-chain check.

## Wire format: ciphertext envelope v1

The envelope is JSON over the existing authenticated HTTP surface. Binary
fields are unpadded base64url. The server treats `ciphertext` as opaque bytes.

```json
{
  "v": 1,
  "id": "01J8Z6Y4Q9K5T3M2N1P0R7S6V4",
  "conversation": "b64url-32-bytes",
  "group": "b64url-opaque-mls-group-id",
  "epoch": 7,
  "suite": 1,
  "kind": "application",
  "sender": "did:plc:alice",
  "senderDevice": "did:key:z6Mk...",
  "recipients": ["did:plc:bob"],
  "recipientDevices": ["did:key:z6Mk..."],
  "ciphertext": "b64url-mls-message",
  "createdAt": "2026-11-16T12:00:00Z"
}
```

Field rules:

- `v` is required and must be `1`. Unknown versions are rejected; there is no
  fallback parser and no plaintext path.
- `id` is a client-generated, globally unique, sortable identifier. It is the
  idempotency key; a duplicate delivery returns the stored result, not a second
  message.
- `conversation` is an opaque random 32-byte identifier chosen by the client.
  The server does not derive it from participant DIDs or plaintext.
- `group` is the MLS group identifier carried for routing/debugging. The server
  does not interpret or rewrite it.
- `epoch` is the MLS epoch the ciphertext belongs to. It is metadata for
  ordering and diagnostics; clients validate it against their MLS state.
- `suite` must be `1` in v1. No suite negotiation and no downgrade.
- `kind` is one of `application`, `welcome` or `commit`. Handshake messages use
  the same envelope and are stored/routed as ciphertext. The server does not
  process their contents.
- `sender` and `recipients` are account DIDs; `senderDevice` and
  `recipientDevices` are device credentials. The server checks that the
  authenticated sender owns `sender`/`senderDevice` and that every recipient
  device is a current device of a recipient. It does not infer group membership
  from these fields.
- `ciphertext` is the complete MLS message (PrivateMessage for application and
  most handshake traffic, Welcome where applicable). It is never decoded,
  searched, previewed or logged.
- `createdAt` is client time for display/retry; server receipt time is separate
  and authoritative for expiry. Clock skew is bounded by the existing API
  policy.
- Maximum envelope size is 64 KiB of ciphertext and 16 KiB of metadata in v1.
  Larger messages use client-side chunking/attachments, not a larger plaintext
  envelope.

The server’s response to a send is metadata only: `id`, `conversation`,
`createdAt`, `delivery` state and per-device acknowledgement counts. It never
returns the plaintext or a server-rendered preview.

## Conversation and group-state storage

| Data | Where it lives | Server may see |
| --- | --- | --- |
| MLS group state, ratchet tree, epoch secrets | Client only, encrypted at rest by the client/OS | Nothing |
| Device private identity key | Client secure storage only | Nothing |
| Key packages (public) | Device uploads to server for pickup; consumed atomically | Opaque public bytes, suite, device id, expiry |
| Welcome/Commit/application ciphertext | Server stores/routes as opaque envelopes | Ciphertext, size, timestamps, routing metadata |
| Conversation id and member DIDs | Server | Yes; this is delivery metadata |
| Delivery/ack state | Server, scoped to recipient device | Yes; no content |
| Plaintext, previews, search index | Never on server | Nothing |

Conceptual server records (not a schema to implement yet):

- **device**: account DID, device id/credential, public identity key, status
  (`active`/`revoked`), created/revoked timestamps.
- **key_package**: device id, suite, opaque key package, one-time consumption
  marker, expiry.
- **conversation**: opaque conversation id, creator, created/updated time,
  membership version. Membership itself is an MLS fact; the server copy is for
  routing and must not be treated as cryptographic authority.
- **envelope**: envelope id, conversation, sender/device, epoch, kind, opaque
  ciphertext, size, created time.
- **delivery**: envelope id, recipient device, state (`pending`/`delivered`/
  `acked`/`expired`), attempts, last error class. It contains no plaintext and
  no decryption error detail.

The server must not store a plaintext body, a searchable token, a push preview
or a notification body for remote direct messages. A push notification may say
only that a message is available, or use a client-generated opaque hint that
does not reveal content.

## Device registration and key packages

1. The client generates its device identity key and a batch of MLS key packages
   locally.
2. It registers the device with the account using an authenticated session and a
   challenge signed by the new device key. The server verifies the signature and
   stores only public material.
3. The client uploads key packages with their suite and expiry. Each package is
   consumed at most once; consumption is atomic, and a consumed package is
   removed or marked unusable.
4. A sender fetches current key packages for the recipient devices it intends to
   add. A missing/expired package is a retryable client error, not a reason to
   fall back to plaintext.
5. Revocation marks a device inactive and removes its unused key packages. A
   revoked device cannot fetch new envelopes. Existing group members remove it
   with an MLS Remove commit; the server cannot forge that commit.
6. Device registration, revocation and key-package counts are visible to the
   account owner so a silent added device can be noticed and removed.

## Message flow

1. **Resolve recipients.** The sender resolves account DIDs and their active
   devices. It verifies that each device is currently bound to the account.
2. **Establish or extend the group.** For a new conversation, the sender creates
   an MLS group and adds one leaf per recipient device using fresh key packages.
   For an existing conversation, it adds/removes devices with MLS proposals and
   a Commit.
3. **Deliver handshake material.** Welcome and Commit messages are wrapped in
   `kind: "welcome"`/`"commit"` envelopes and delivered only to the affected
   recipient devices. The server stores and forwards ciphertext; it does not
   apply the Commit.
4. **Send application messages.** The sender encrypts with MLS, wraps the
   resulting message in a `kind: "application"` envelope and submits it. The
   server validates metadata and authorization, assigns receipt time and fans out
   to the listed recipient devices only.
5. **Receive and acknowledge.** A recipient fetches pending envelopes, verifies
   the sender/device binding, processes the MLS message, deduplicates by
   envelope id and acknowledges per device. The server clears the delivery only
   after a valid acknowledgement.
6. **Remove or rotate.** Removing a device requires an MLS Remove Commit. A
   suspected key compromise also triggers an Update and a fresh key package for
   each remaining device; old ciphertext is not re-encrypted.

## Error cases and client behaviour

| Condition | Server response | Client behaviour |
| --- | --- | --- |
| Unsupported envelope version | `400 unsupported_envelope_version` | Do not retry with v1; upgrade the client. |
| Unsupported ciphersuite | `400 unsupported_ciphersuite` | Do not downgrade; surface a compatibility error. |
| Duplicate envelope id | Success with the original receipt | Treat as already sent/delivered. |
| Sender/device mismatch or revoked device | `403 device_not_authorized` | Re-authenticate/register; never resend as plaintext. |
| Recipient device not active | `409 recipient_device_unavailable` | Refresh the device list and key packages. |
| Stale/unknown epoch | `409 epoch_conflict` (metadata) | Fetch handshake/commit material; do not decrypt out of order. |
| Missing/expired key package | `409 key_package_unavailable` | Ask the recipient device to replenish; retry later. |
| Replayed/reordered ciphertext | Accepted for idempotent delivery, rejected by MLS | Discard; do not surface as a new message. |
| Ciphertext too large | `413 envelope_too_large` | Chunk or send an encrypted attachment reference. |
| Conversation not found/unauthorized | `404`/`403` | Do not probe; show a generic failure. |
| Feature disabled | `503 e2ee_unavailable` | No plaintext fallback; keep the message local/unsent. |
| Internal storage failure | `500` with retryable error class | Retry with the same envelope id. |

Decryption failures are client-side and must not be reported to the server with
ciphertext or plaintext detail. The server never attempts decryption, so a
server “decryption error” is a design bug.

## Migration and feature gate

- The feature is disabled by default (`messaging.e2ee.enabled = false`). There
  is no route that accepts remote DM plaintext in the meantime.
- No plaintext DM history is migrated into the E2EE store. Existing local-only
  direct posts keep their current representation and are not silently converted
  into remote messages.
- Enabling the gate requires: the MLS client implementation, envelope routes,
  device/key-package storage, delivery worker, the adversarial tests from the
  threat model, RFC 9420 interoperability vectors and independent review.
- A conversation created before the gate is not retroactively encrypted. A new
  encrypted conversation gets a new opaque conversation id.
- Rollback disables new sends and preserves ciphertext for clients that already
  have the key material; it never falls back to plaintext.

## Consequences

- Issue #19’s design questions are closed: threat model, ciphersuite, envelope
  format, storage boundary, device registration, flow, errors and gate are
  specified.
- Remaining work is implementation and review: a vetted MLS client provider,
  server envelope/device/key-package/delivery code, routes, tests and an
  independent security assessment.
- No production code or tests are added by this ADR. The absence of DM code is
  intentional and is not a stub.
- ADR 0009 remains the product decision and keeps the exact boundary against
  unencrypted remote DMs; this ADR only supplies the missing design.
