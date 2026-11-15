# Native AT Protocol PDS

This guide covers the optional native PDS from
[ADR 0008](../adr/0008-native-pds.md). It is a working repository and identity
service, not just a protocol library: a host can enable it, create a DID,
write records and export a signed repository over HTTP. The external-PDS
publishing path in [AT Protocol publishing](atproto.md) is independent and
unchanged.

## What exists

`Protocol\Atproto\Repository` provides the wire primitives:

| Class | Responsibility |
| --- | --- |
| `Cid` | CIDv1, dag-cbor codec, SHA-256 multihash, lowercase base32 multibase |
| `DagCbor` | canonical DAG-CBOR maps, lists, text, byte strings, integers and CID links |
| `Car` | CAR v1 header, one root, block framing, CID verification and duplicate rejection |
| `MstKey` / `MstEntry` / `MstNode` | AT Protocol MST key rules, prefix compaction, node encoding and strict decoding |
| `MstBuilder` | deterministic multi-layer MST construction, including empty structural nodes |
| `Mst` | read-only lookup and full traversal with block, cycle, range and layer checks |
| `RepoCommit` | version-3 signed commit encoding, signing, verification and CID |
| `Ecdsa` | raw 64-byte P-256 `r‖s` conversion around OpenSSL |

`Domain\Atproto\Pds` provides the durable service:

| Class | Responsibility |
| --- | --- |
| `PdsSchema` | identity, key, record, commit, block and PLC-operation tables |
| `PdsRepository` | host-neutral persistence contract |
| `InMemoryPdsRepository` / `PdoPdsRepository` | ephemeral and transactional SQL stores |
| `NativePdsService` | identity creation, DID documents, records, signed commits, CAR export and rotation |
| `PdsDid` / `PdsPlc` | `did:plc` generation and signed PLC operations |
| `PdsKeyGenerator` | P-256 key pairs and Multikey encoding |
| `PdsRev` | strictly increasing 13-character repository revisions |

Private signing and rotation keys are sealed with the host-supplied
`Security\SecretBox`; only the sealed payload is stored. The public key is
stored as PEM for verification and as a Multikey for the DID document.

## Enabling it

The native PDS is disabled by default. `Application::inMemory()` and
`Application::forHost()` build it when `social/atproto.pds.enabled` is true:

```php
$config = new ArrayConfig(['social' => [
    'atproto.pds.enabled' => 'true',
    'atproto.pds.public_url' => 'https://pds.example',
    'atproto.pds.handle_domain' => 'pds.example',
    'atproto.pds.secret_box_key' => $secretBoxKey, // base64, 32 bytes
]]);

$app = Application::forHost(
    config: $config,
    database: $database,
    // ... the host's normal ports ...
);
```

A host that already has a `SecretBox` passes it as `pdsSecrets:` instead of
putting the key in configuration. `forHost()` uses `PdoPdsRepository` by
default; `inMemory()` uses `InMemoryPdsRepository`, and either accepts an
explicit `pdsRepository:`.

Create the tables with `PdsSchema::create($database)` in the host migration
step. `PdoPdsRepository` uses one transaction for each commit, block set,
record change and identity-head update, so a crash cannot leave a record
without its commit.

With the switch off, the routes are still in the manifest but answer
`503 PdsNotEnabled`. A host that enables the service without a secret box or
public URL fails at boot or on the first identity operation; it never stores an
unsealed key or writes a DID document with an empty endpoint.

## HTTP surface

All routes use the normal kernel, tenant-scoped authenticated user and XRPC
error shape `{error, message}`. The host authenticates and sets
`Request::$userId`; the service only writes to that account's DID.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/xrpc/com.atproto.identity.createDid` | yes | Create a DID and empty signed repository |
| `GET` | `/xrpc/com.atproto.identity.resolveDid?did=…` | no | Return the local DID document |
| `POST` | `/xrpc/com.atproto.identity.rotateKeys` | yes | Rotate signing and rotation keys |
| `POST` | `/xrpc/com.atproto.repo.createRecord` | yes | Append a record and a signed commit |
| `GET` | `/xrpc/com.atproto.repo.getRecord?repo=…&collection=…&rkey=…` | no | Read one record |
| `GET` | `/xrpc/com.atproto.repo.listRecords?repo=…&collection=…` | no | Page a collection (limit 1–100) |
| `GET` | `/xrpc/com.atproto.repo.describeRepo?repo=…` | no | DID document and collections |
| `GET` | `/xrpc/com.atproto.sync.getRepo?did=…` | no | Full repository as `application/vnd.ipld.car` |

A write request looks like this:

```json
{
  "repo": "did:plc:abcdefghijklmnopqrstuvwx",
  "collection": "app.bsky.feed.post",
  "rkey": "3kabc",
  "record": {
    "$type": "app.bsky.feed.post",
    "text": "hello",
    "createdAt": "2026-11-15T00:00:00Z"
  }
}
```

`repo` may be the account's DID or handle; a different account's repository is
refused with `403 Forbidden`. `rkey` is optional and is generated as a TID when
omitted. `record.$type` is required to equal `collection`; the value is stored
as canonical DAG-CBOR, so its CID is stable for the same JSON map.

The response contains the record `uri`, its `cid`, the new commit `cid`/`rev`
and `validationStatus: "valid"`. A duplicate collection/rkey is
`RecordAlreadyExists`, a missing record is `RecordNotFound` (404), and an
unknown repo is `RepoNotFound` (400).

## Commits and MST

Every write rebuilds the complete MST from the current records and creates a
version-3 commit:

```
previous commit CID ──► new commit
                         did, rev, prev, data = new MST root
                         sig = ECDSA-P256 over unsigned DAG-CBOR
```

The stored commit is addressed by its own CID and includes its signature; the
record block, every new MST node block and the commit block are stored in the
same transaction. `rev` is a 13-character TID-style value with microseconds
and is clamped above the previous revision, so two writes in one microsecond
still increase it. Empty repositories start with a signed commit whose data is
the official empty-MST root.

`sync.getRepo` returns every stored block, rooted at the current commit, so a
consumer can decode the commit, verify its signature against the signing key
and walk the MST. It does not implement `since` or incremental sync yet.

## DID and key lifecycle

`createDid` derives a `did:plc` identifier from a random genesis seed and
writes a DID document with:

- `#atproto` — the signing Multikey;
- `#atproto_rotation` — the rotation Multikey;
- `alsoKnownAs: ["at://handle"]`;
- `service` `#atproto_pds` with the configured public URL.

The genesis PLC operation is signed by the rotation key and stores both public
keys, the handle and the service endpoint. `resolveDid` resolves DIDs hosted by
this PDS; there is no PLC directory transport, so an external resolver cannot
discover a locally created DID until a host publishes the operation.

`rotateKeys` generates a new signing and rotation pair, signs the next PLC
operation with the **old rotation key**, links it to the previous operation via
`prev`, marks both old keys inactive and makes the new signing key the only
active signing key. Existing commits remain verifiable with the old public key;
new commits verify only with the new key. The old keys stay in the store for
history and are never reactivated.

## Limits and refusals

- CID: only CIDv1 dag-cbor SHA-256 with a 32-byte digest and lowercase base32.
- DAG-CBOR: no floats, indefinite lengths, unknown tags, duplicate/non-canonical
  map keys, invalid UTF-8 or trailing bytes. Nesting is capped at 64 levels.
- CAR: one root, at most 10,000 blocks, at most 8 MiB per framed block,
  mandatory root block, unique CIDs and a verified CID for every block.
- MST: keys are `collection/rkey` paths up to 1,024 bytes; entries are strictly
  sorted, prefix compaction must be canonical, a node may not mix layers, child
  layers must be lower and only the root may be empty.
- Commit: version 3, a DID, a non-empty revision and a 64-byte signature.
- PDS: a collection must be a dotted NSID, a record key uses the AT Protocol
  record-key grammar, a repository is bounded to 10,000 local records, and
  `listRecords` clamps `limit` to 1–100.

## Compatibility vectors

The tests use vectors generated with the Bluesky `@atproto/repo`,
`@atproto/lex-cbor` and `@atproto/crypto` packages:

- canonical DAG-CBOR for `{count: 2, hello: "world"}`;
- the official empty-MST CID;
- official single- and twelve-entry MSTs, including their exact multi-layer
  block CIDs;
- a three-entry MST and its CAR;
- a signed version-3 commit verified by both OpenSSL and the reference
  verifier during development.

The vectors are in `tests/Protocol/Repository`. A change to map ordering, CID
framing, prefix compaction, layer placement or the signing input fails there
instead of silently changing repository hashes.

`tests/Integration/PdsHttpTest.php` is the end-to-end gate: it starts a real
PHP HTTP server over a SQLite file, creates a DID over TCP, writes and reads
records, verifies every commit signature and MST root, restarts the server and
reads the unchanged repository again, then rotates keys and proves that only
the new key signs the next commit.

## What is not implemented

- Blob upload/read authorization, MIME/size policy and quota accounting.
- The firehose, subscriber cursors, replay and reconciliation.
- `putRecord`, `deleteRecord`, `importRepo` and update/delete commit paths.
- PLC directory submission, recovery/tombstone operations and handle proof.
- Migration from a linked external PDS.

Those remain follow-ups in [ADR 0008](../adr/0008-native-pds.md). Remote direct
messages remain blocked behind the E2EE/MLS decision in
[ADR 0009](../adr/0009-e2ee-direct-messages.md); the native repository stores
public records only.
