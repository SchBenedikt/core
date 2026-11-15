# ADR 0008 — Optional native PDS with Core-owned identity and repository

- Status: accepted
- Date: 2026-11-15
- Related: [ADR 0001](0001-shared-core-two-hosts.md),
  [ADR 0006](0006-host-owned-accounts.md),
  [ADR 0007](0007-two-protocols-one-model.md), issue #17

## Context

The core already publishes to an **external** PDS: a host links an account,
stores the OAuth session, and `XrpcAtprotoPublisher` writes records through
`com.atproto.repo.putRecord`. That is not a PDS. It has no hosted DID, no
repository commit chain, no CAR/MST store, no PDS XRPC surface and no firehose.

Issue #17 treats a native PDS as an optional, strategic milestone. The product
decision is now explicit: **Core implements a full native PDS, including the
DID/PLC key lifecycle**, rather than only a protocol library around a host that
owns identity. The existing external-PDS client remains a separate feature.

The host-neutral boundary still applies. The core cannot open sockets, read
host sessions or store unsealed credentials. The PDS is domain/protocol logic
with ports; the host supplies the process, HTTP transport, account system and
secret storage.

## Decision

**A native PDS is an optional Core subsystem. It is disabled by default and
does not change the external-PDS linking path.** A host may run one or both,
but an account uses exactly one repository at a time. Moving an account from a
linked PDS to a native repository, or between repositories, is an explicit
migration with a new commit and no silent DID change.

### Ownership

Core owns:

- The DID lifecycle state machine for native accounts: DID creation,
  PLC operation construction, signing-key and rotation-key references,
  rotation, recovery and tombstone rules.
- The repository engine: CID, canonical DAG-CBOR, CAR, MST, commit chain,
  revision/TID ordering, record validation and repository integrity checks.
- The PDS XRPC services (`describeRepo`, `getRecord`, `listRecords`,
  `createRecord`, `putRecord`, `deleteRecord`, `importRepo`, `exportRepo`,
  blob references and cursors) as host-neutral services.
- Durable firehose sequencing, subscriber cursors, replay and reconciliation
  with the canonical local post model.
- Blob metadata, MIME/size authorization, quota accounting and repository
  rate-limit policy. Blob bytes stay behind `BlobStore`.
- The native repository store and atomic commit transitions.

The host owns:

- Process lifecycle, HTTP server/routes, TLS, request authentication and
  account-to-local-user mapping.
- The actual secret store; private keys are sealed with `Security\SecretBox`
  and never written into a repository record, source tree, log or CAR file.
- PLC directory transport behind a port, admin/moderation UI, abuse handling,
  deployment, backup and the decision whether native PDS is enabled.
- Quota values and rate-limit configuration; Core enforces them.

### Identity and keys

- `did:plc` is the primary native identity because it is portable and supports
  rotation. `did:web` is a later compatibility option, not part of the first
  implementation.
- A native account has a signing key and at least one rotation/recovery key.
  The private material is referenced by an opaque key id; Core never persists
  PEM or raw private keys itself.
- Key rotation creates a PLC operation that proves control with the current
  rotation key and updates the DID document. A failed PLC submission leaves
  the previous state active; an operation is idempotent by its operation CID.
- A native DID is never derived from a linked external DID. Importing or
  migrating a repository is a separate operation and must preserve record CIDs
  where the source repository is compatible.
- Losing every rotation key is unrecoverable by design. Recovery policy and
  custody are the host's responsibility, not a Core backdoor.

### Records, privacy and blobs

- The native PDS hosts **public AT Protocol repository records**. Private
  messages are not placed in a public repository under a false privacy claim.
  Remote direct messages stay blocked until the E2EE design in
  [ADR 0009](0009-e2ee-direct-messages.md) is implemented.
- Record writes are validated against the supported lexicon slice and the
  repository key format before a commit is created. Unknown or oversized
  records are refused, not silently stored.
- Blob uploads are authorized by the authenticated repository owner, MIME
  allowlist, byte limit and account quota. A blob reference does not grant
  read access by itself; serving a blob checks the repository and account
  policy.
- CAR/MST input is untrusted. Decoding verifies every block CID, rejects
  cycles, duplicate blocks, missing roots, excessive depth/size, non-canonical
  DAG-CBOR and invalid signatures before any record is indexed.

### Threat model

The first implementation must cover at least:

- signing-key or rotation-key compromise, including rotation replay and a
  forged PLC operation;
- a malicious CAR/MST that causes cycles, unbounded traversal, CID mismatch,
  duplicate records or a memory denial of service;
- replayed or out-of-order commits and firehose events;
- unauthorized blob reads or writes, MIME confusion and quota exhaustion;
- repository/identity rollback or a silent DID change during migration;
- cross-account record access through a missing owner check;
- firehose subscriber lag, cursor reuse and reconciliation after a gap.

### Delivery stages

1. **ADR and protocol foundation:** accepted decision, canonical
   CID/DAG-CBOR/CAR/MST read and commit signing/verification, official vectors
   and malformed-input tests.
2. **Repository store:** durable commit transactions, a multi-layer MST writer,
   record/blocks/identity schemas and owner checks.
3. **PDS XRPC:** `createDid`, `resolveDid`, `rotateKeys`, `createRecord`,
   `getRecord`, `listRecords`, `describeRepo` and `sync.getRepo`, with XRPC
   errors and host route mapping.
4. **DID/PLC lifecycle:** `did:plc` genesis, signed PLC operations, sealed
   signing/rotation keys and rotation that makes only the new signing key
   valid.
5. **Blob authorization:** upload/read policy, quotas and a host-backed blob
   store.
6. **Firehose:** durable sequence, subscriber cursors, replay and resource
   limits.
7. **Migration and the remaining repo methods:** explicit handoff from a linked
   or legacy PDS, plus `putRecord`/`deleteRecord`/`importRepo` and blob methods.

Each stage gets its own implementation issue. A successful local write is not
proof that a relay or AppView indexed it; that requires a real-peer check.

## Consequences

**Good**

- The native PDS is a real, testable Core subsystem instead of host-specific
  glue in two products.
- Identity and repository rules live in one place and can be exercised with
  official protocol vectors.
- The external-PDS client keeps working independently.

**Costs**

- This is a large security-sensitive surface: key custody, PLC, repository
  integrity, blob authorization and firehose delivery.
- A full native PDS is not required for the ActivityPub-only release. It stays
  optional and disabled until the staged gates pass.
- Hosts must supply durable secret storage and a PLC transport; the Core cannot
  hide those operational responsibilities.

## Implementation status

As of 2026-11-15, stages 1–4 are implemented:

- `src/Protocol/Atproto/Repository` has CID, canonical DAG-CBOR, CAR, MST
  validation, read-only traversal, a multi-layer `MstBuilder` that matches the
  reference root and blocks, and P-256 signed commits, with official vectors in
  `tests/Protocol/Repository`.
- `src/Domain/Atproto/Pds` has the durable PDS schema, in-memory and PDO stores,
  `did:plc` generation, DID documents, signed PLC operations, sealed key
  lifecycle, rotation, record writes with chained version-3 commits and CAR
  export.
- The XRPC routes are served by `PdsXrpcHandler` behind the normal kernel and
  auth middleware; `Application` builds the optional service from
  `atproto.pds.*` configuration.
- `tests/Integration/PdsHttpTest.php` starts a real PHP HTTP server over SQLite
  and verifies DID creation/resolution, record reads/lists, commit signatures,
  restart persistence and rotation.

**Not implemented:** blob upload/authorization and quota accounting, the
firehose, `putRecord`/`deleteRecord`, `importRepo`, PLC directory transport,
recovery/tombstone operations, and migration from a linked PDS. The native PDS
remains disabled by default and is not a claim that a public relay has indexed
its records.
