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
| `PdsSchema` | identity, key, record, commit, block, blob and PLC-operation tables |
| `PdsRepository` | host-neutral persistence contract |
| `InMemoryPdsRepository` / `PdoPdsRepository` | ephemeral and transactional SQL stores |
| `NativePdsService` | identity creation, DID documents, records, signed commits, CAR export, incremental `since` export, PLC publication and rotation |
| `PdsDid` / `PdsPlc` / `PdsPlcOperation` | `did:plc` generation, signed PLC operations and their JSON wire form |
| `PdsDirectory` / `PlcDirectoryClient` | host-neutral PLC submission port and its standard HTTP client |
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
explicit `pdsRepository:`. Pass a `PdsDirectory` as `pdsDirectory:` to publish
geneses and rotations; `PlcDirectoryClient` uses the host's `HttpClient` and
the public PLC directory by default. Without a directory the identity is still
created locally and can be retried later with `identity.submitPlcOperation`.
The directory is an external service, so its transport stays behind the port.

Create the tables with `PdsSchema::create($database)` in the host migration
step; an existing installation calls `PdsSchema::upgrade($database)` for the
blob and firehose tables. `PdoPdsRepository` uses one transaction for each
commit, block set, record changes and identity-head update, so a crash cannot
leave a record without its commit. Blob bytes go to the host `BlobStore` under
`pds/blobs/<sha256(did)>/<cid>.bin`; the metadata row is written after the
bytes, and quota accounting sums that DID's rows only.

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
| `GET` | `/xrpc/com.atproto.identity.resolveHandle?handle=…` | no | Resolve a local handle to its DID |
| `POST` | `/xrpc/com.atproto.identity.rotateKeys` | yes | Rotate signing and rotation keys |
| `POST` | `/xrpc/com.atproto.identity.submitPlcOperation` | yes | Re-submit the newest signed PLC operation |
| `POST` | `/xrpc/com.atproto.repo.createRecord` | yes | Append a record and a signed commit |
| `POST` | `/xrpc/com.atproto.repo.putRecord` | yes | Create or replace one record and append a commit |
| `POST` | `/xrpc/com.atproto.repo.deleteRecord` | yes | Delete one record and append a commit |
| `POST` | `/xrpc/com.atproto.repo.applyWrites` | yes | Apply up to 200 creates, updates and deletes in one commit |
| `POST` | `/xrpc/com.atproto.repo.uploadBlob` | yes | Store an immutable blob, returning its CID, media type and size |
| `POST` | `/xrpc/com.atproto.repo.importRepo` | yes | Import a CAR archive into the signed-in empty repository |
| `GET` | `/xrpc/com.atproto.repo.getRecord?repo=…&collection=…&rkey=…` | no | Read one record, optionally pinned to a `cid` |
| `GET` | `/xrpc/com.atproto.repo.listRecords?repo=…&collection=…` | no | Page a collection (limit 1–100) |
| `GET` | `/xrpc/com.atproto.repo.describeRepo?repo=…` | no | DID document and collections |
| `GET` | `/xrpc/com.atproto.server.describeServer` | no | Handle domains and server DID |
| `GET` | `/xrpc/com.atproto.sync.getRepo?did=…` | no | Full repository as `application/vnd.ipld.car` |
| `GET` | `/xrpc/com.atproto.sync.getBlocks?did=…&cids=…` | no | Requested blocks plus the current commit as CARv1 |
| `GET` | `/xrpc/com.atproto.sync.subscribeRepos?cursor=…` | no | WebSocket firehose; plain HTTP answers `426` |
| `GET` | `/xrpc/com.atproto.sync.getLatestCommit?did=…` | no | Current commit CID and revision |
| `GET` | `/xrpc/com.atproto.sync.listRepos` | no | Page the hosted repositories |
| `GET` | `/xrpc/com.atproto.sync.getBlob?did=…&cid=…` | no | Read stored blob bytes with their media type |
| `GET` | `/xrpc/com.atproto.sync.listBlobs?did=…` | no | Page blob metadata |

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
refused with `403 Forbidden`. For `createRecord`, `rkey` is optional and is
generated as a TID when omitted; `putRecord`, `deleteRecord` and the update and
delete entries of `applyWrites` require it. `record.$type` is required to equal
`collection`; the value is stored as canonical DAG-CBOR, so its CID is stable
for the same JSON map.

The response contains the record `uri`, its `cid`, the new commit `cid`/`rev`
and `validationStatus: "valid"`. A duplicate collection/rkey is
`RecordAlreadyExists`, a missing record is `RecordNotFound` (404), and an
unknown repo is `RepoNotFound` (400). `putRecord` replaces an existing record
while preserving its original creation timestamp. `deleteRecord` and
`applyWrites` return the new `commit`; `applyWrites` also returns one result per
operation and validates the whole batch before writing anything.

Blob upload uses the raw request body and its `Content-Type`. The response is
`{"blob": {"$type": "blob", "ref": {"$link": "bafkrei…"}, "mimeType": ...,
"size": ...}}`. Blobs are immutable and scoped to the authenticated DID; the
same content uploaded twice reuses one row. Empty blobs, malformed media types,
blobs over 5 MiB and repositories over 50 MiB of blob data are refused. The
bytes go through the host `BlobStore`; `sync.getBlob` and `sync.listBlobs`
return the same metadata and content-addressed CID.

`importRepo` accepts the raw CAR request body and requires an empty repository.
It decodes the source commit, walks and validates the source MST, checks every
record block and rebuilds the records under the target DID. The target PDS then
signs a new commit with its own active key, so the repository head and commit
CID change while every record CID stays identical. `sync.getRepo` is the
export side of the same path.

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
and walk the MST. `sync.getBlocks` returns the current commit plus every
requested block, rooted at the head, and fails on an unknown CID.
`sync.getLatestCommit` exposes the head without a CAR and `sync.listRepos`
pages the hosted identities. `getRepo?since=<rev>` returns only blocks that
are reachable from the current MST but not from that earlier revision, plus
the current commit. An unknown or non-ancestor revision falls back to a
complete export instead of returning a CAR the client cannot apply.

## Firehose

The durable `social_pds_event` rows are written in the same transaction as the
commit. `seq` comes from `social_pds_sequence`, so it is global, monotonic and
gap-free across repositories. Every record mutation and every blob upload
appends a `#commit` event containing `repo`, `commit`, `rev`, `since`, `ops`,
`blobs` and a complete CARv1 `blocks` payload. The blob event has an empty
`ops` list and carries the raw blob CID in `blobs`.

The WebSocket process is `bin/pds-firehose`. For the standalone SQLite setup,
the existing path option remains available:

```sh
php bin/pds-firehose --database=/var/lib/aloha/pds.sqlite --port=2584
```

Hosts that use the main application database can give the process the same PDO
DSN and credentials as the HTTP host, without putting a password in the process
arguments:

```sh
PDS_DATABASE_DSN='pgsql:host=127.0.0.1;port=5432;dbname=alohasocial' \
PDS_DATABASE_USER=alohasocial PDS_DATABASE_PASSWORD='…' \
php bin/pds-firehose --host=127.0.0.1 --port=2584
```

`PDS_DATABASE_DSN`, `PDS_DATABASE_USER` and `PDS_DATABASE_PASSWORD` configure
PDO directly. `--dsn=…` can be used instead of `PDS_DATABASE_DSN`; the legacy
`--database` option and `PDS_DATABASE` environment variable continue to mean a
SQLite path.

It serves `/xrpc/com.atproto.sync.subscribeRepos` on the socket. A subscriber
without `cursor` starts at the current sequence; `cursor=N` replays every
event with `seq > N` from the persisted log before following live commits.
Frames are DAG-CBOR `#commit` messages in binary WebSocket frames. The normal
HTTP route is registered as well but answers `426 UpgradeRequired`; the socket
process is the transport, not a second repository implementation.

## DID and key lifecycle

`createDid` derives a `did:plc` identifier from a random genesis seed and
writes a DID document with:

- `#atproto` — the signing Multikey;
- `#atproto_rotation` — the rotation Multikey;
- `alsoKnownAs: ["at://handle"]`;
- `service` `#atproto_pds` with the configured public URL.

The genesis PLC operation is signed by the rotation key and stores both public
keys, the handle and the service endpoint. When a `PdsDirectory` is configured,
the operation is submitted before `createDid` returns; a rejected operation is
an `UpstreamFailure` and can be retried with `submitPlcOperation` without
changing the DID or keys. `resolveDid` resolves DIDs hosted by this PDS. The
bundled `PlcDirectoryClient` posts the signed operation to the standard PLC
HTTP endpoint; a host may supply its own transport with the same port.

`rotateKeys` generates a new signing and rotation pair, signs the next PLC
operation with the **old rotation key**, links it to the previous operation via
`prev`, marks both old keys inactive and makes the new signing key the only
active signing key. Existing commits remain verifiable with the old public key;
new commits verify only with the new key. The old keys stay in the store for
history and are never reactivated.

## Limits and refusals

- CID: only CIDv1 dag-cbor SHA-256 with a 32-byte digest and lowercase base32;
  raw-codec CIDs are accepted only for blob references and blob firehose data.
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
PHP HTTP server and a real `bin/pds-firehose` WebSocket process over one SQLite
file, creates a DID over TCP, writes and reads records, verifies every commit
signature and MST root, restarts the server and reads the unchanged repository
again, then rotates keys and proves that only the new key signs the next
commit. Later cases cover put/delete/batch commits, blob upload/read/list
through a durable `LocalBlobStore`, cross-repository blob isolation, CAR import
with identical record CIDs, `getBlocks`, and a WebSocket subscriber that
receives every mutation and replays from an earlier cursor.

## Deliberately outside the PDS surface

All `com.atproto.repo.*`, `com.atproto.identity.*` and `com.atproto.sync.*`
endpoints listed above are implemented. The following are deliberately not a
second account surface in this library:

- Account recovery, tombstone and handle-proof workflows. The host owns the
  account credentials and verification transport (ADR 0006); the PDS provides
  signed PLC operations and repository state, not a second account table or a
  plaintext key-escrow endpoint. A host that deletes an account also decides
  what happens to its local repository and blob data.
- Migration from a linked external PDS as a host workflow; the local
  `importRepo`/`getRepo` pair is the protocol primitive it uses.
- Relay indexing and AppView reconciliation. A PDS can serve the firehose, but
  a public relay choosing to consume it is a separate service.

Remote direct messages remain blocked behind the E2EE/MLS decision in
[ADR 0009](../adr/0009-e2ee-direct-messages.md); the native repository stores
public records only and does not pretend to implement plaintext DMs.
