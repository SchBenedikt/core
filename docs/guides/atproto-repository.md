# AT Protocol repository primitives

This guide covers the first native-PDS milestone from
[ADR 0008](../adr/0008-native-pds.md). It is a protocol library, not a running
PDS yet. The external-PDS publishing path in
[AT Protocol publishing](atproto.md) is unchanged.

## What exists

`Protocol\Atproto\Repository` provides:

| Class | Responsibility |
| --- | --- |
| `Cid` | CIDv1, dag-cbor codec, SHA-256 multihash, lowercase base32 multibase |
| `DagCbor` | canonical DAG-CBOR maps, lists, text, byte strings, integers and CID links |
| `Car` | CAR v1 header, one root, block framing, CID verification and duplicate rejection |
| `MstKey` / `MstEntry` / `MstNode` | AT Protocol MST key rules, prefix compaction, single-layer node encoding and strict decoding |
| `Mst` | read-only lookup and full traversal with block, cycle, range and layer checks |
| `RepoCommit` | version-3 signed commit encoding, signing, verification and CID |
| `Ecdsa` | raw 64-byte P-256 `r‖s` conversion around OpenSSL |

The classes use no host API and no new Composer dependency. Private key
material is passed in as a PEM string by the caller; the repository layer does
not persist it.

## Example

```php
use AlohaSocial\Core\Protocol\Atproto\Repository\Car;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;
use AlohaSocial\Core\Protocol\Atproto\Repository\RepoCommit;

$record = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'hello']);
$recordCid = Cid::forData($record);

$commit = RepoCommit::sign(
    did: 'did:plc:example',
    rev: '3kzzzzzzzzzzz',
    previous: null,
    data: $recordCid,
    privateKeyPem: $privateKey,
);

$archive = new Car($recordCid, [$recordCid->toString() => $record]);
$bytes = $archive->encode();
```

A CAR must contain its declared root block. Use `Car::decode($bytes)` to verify
every block CID before passing the block map to `Mst`.

## Compatibility vectors

The tests use vectors generated with the Bluesky `@atproto/repo`,
`@atproto/lex-cbor` and `@atproto/crypto` packages:

- canonical DAG-CBOR for `{count: 2, hello: "world"}`;
- the official empty-MST CID;
- a three-entry MST and its CAR;
- a signed version-3 commit verified by both OpenSSL and the reference
  verifier during development.

The vectors are in `tests/Protocol/Repository`. The commit test fixes a
P-256 key pair and checks the exact unsigned bytes, CID and signature. A
change to map ordering, CID framing, prefix compaction or the signing input
therefore fails the test instead of silently changing repository hashes.

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
  Signing uses SHA-256 over the DAG-CBOR of the unsigned commit.

## What is not implemented

The repository store, blob authorization, PDS XRPC services, DID/PLC lifecycle,
key rotation, host routes and firehose are later stages. In particular:

- there is no `RepositoryStore`, `BlobStore` authorization or atomic commit
  transaction yet;
- `MstNode::forEntries()` only builds a single-layer node, which is enough for
  fixtures and lookup tests, not a general multi-layer repository writer;
- there is no DID document or PLC operation;
- no PDS route is registered and no native PDS is enabled by default.

Those gaps are deliberate. [ADR 0008](../adr/0008-native-pds.md) defines the
security gates and follow-up stages. A host must not expose a read/write PDS
surface until the store, auth and XRPC stages exist.
