# ADR 0011 — Account data export and erasure stay in the existing tables

- Status: accepted
- Date: 2026-11-16
- Related: [ADR 0006](0006-host-owned-accounts.md),
  [ADR 0004](0004-incremental-migration-and-ownership.md)

## Context

The core stores one account's data in the tables of the feature that owns it:
posts and their revisions, poll rows, favourites, bookmarks, boosts, reactions,
stories and their receipts, follows, blocks, mutes, lists, notifications,
moderation rows, media metadata, recommendation state, search rows, scheduled
posts and AT Protocol publications/PDS records. An account can also own blob
objects in the host's `BlobStore`.

A complete export and a complete erasure therefore cannot be implemented by a
single feature repository, and adding an archive table would create a second
copy of data that could drift from the rows the application actually reads.
The account record itself, credentials and sessions are not core data; ADR 0006
leaves them host-owned.

Some tables have no reliable local-account column. `social_job` stores a JSON
payload and no subject id; `social_activity_log` stores only an activity id;
remote post/thread/account caches and `social_search_trend` are shared instance
state; `social_moderation_action` is an instance moderation record. A query
cannot attribute those rows to one account without a schema change that would
affect unrelated features.

## Decision

The core exposes two authenticated routes:

- `GET /api/v1/account/export` reads the owning rows from the existing tables
  and returns a deterministic JSON archive named
  `alohasocial-account-archive`, version `1`. `data` is keyed by the real table
  names and contains the raw stored rows; `blobs` contains the attachment and
  PDS object bytes as base64, and `missing_blobs` is explicit. There is no
  generated timestamp or random value, so the same stored data produces the
  same archive. Sealed PDS private keys are omitted from the key rows and are
  removed on erasure.
- `DELETE /api/v1/account/data` removes the same rows in one database
  transaction, child-first, then removes the account's attachment and PDS
  blobs. It reports the affected row count per table and the blob count. A
  blob failure is reported as an incomplete erasure; it never becomes a
  silent success.

The adapter is `PdoAccountDataRepository`, behind the
`AccountDataRepository` port. It is not a new persistence layer: every
statement reads or deletes one of the existing `social_*` tables. The host
assembly (`Application::forHost`) supplies the PDO connection; the in-memory
assembly has no durable account data and the routes honestly answer
`503 account_data_unavailable`.

Deletion deliberately preserves foreign data:

- a post by another account remains even when the deleted account was one of
  its direct recipients; only the recipient row is removed;
- another account's lists, collections, posts and profile rows remain;
- the deleted account's membership in another account's list is removed, but
  the other list itself is not;
- reactions, votes and story receipts on the deleted account's content are
  removed as dependent rows, while the same actors' rows on their own content
  remain;
- incoming quote grants that name the deleted actor are left with their owner;
  only the deleted account's own grants are removed.

The rows in `not_attributable` are returned by the export with a reason and are
not deleted. Changing that requires a feature-specific owner column or payload
contract, which this ADR does not invent.

## Consequences

A host gets a real export/deletion boundary without copying the data model.
The archive is a stable machine-readable format, but it is not a portable
ActivityPub or AT Protocol export: it preserves the core's storage rows. A
host that wants an import format must define one on top of this archive.

The host must delete its account, credentials and session after a successful
core erasure. That is the remaining, deliberately host-owned part of "delete
my account"; the core cannot address a table it does not own.
