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

Some tables have no reliable local-account column. `social_activity_log` stores
only an activity id; remote post/thread/account caches and
`social_search_trend` are shared instance state; `social_moderation_action` is
an instance moderation record. A query cannot attribute those rows to one
account without a schema change that would affect unrelated features.

`social_job` used to have the same problem: its payload was not a reliable
owner signal. New account-originated jobs now carry an explicit
`subject_type`/`subject_id` envelope, so a deletion can cancel exactly that
account's queued work. Legacy jobs without an envelope remain unattributable.

## Decision

The core exposes three authenticated routes:

- `GET /api/v1/account/export` reads the owning rows from the existing tables
  and returns a deterministic JSON archive named
  `alohasocial-account-archive`, version `1`. `data` is keyed by the real table
  names and contains the raw stored rows; `blobs` contains the attachment and
  PDS object bytes as base64, and `missing_blobs` is explicit. There is no
  generated timestamp or random value, so the same stored data produces the
  same archive. Sealed PDS private keys are omitted from the key rows and are
  removed on erasure. The archive includes the current `deletion` status.
- `DELETE /api/v1/account/data` runs a durable lifecycle. It first writes a
  `frozen` row to `social_account_deletion`, which is the write gate read by
  the HTTP middleware and the job worker; it then cancels queued and reserved
  jobs whose subject envelope names the account, removes the rows child-first
  in one transaction, removes the blobs, and stores `erased` with the report
  and timestamps. A missing row or blob is not an error. Repeating the call
  returns the stored result; a concurrent call receives the current
  `202` state instead of a second deletion result.
- `GET /api/v1/account/deletion` returns the durable status and report. An
  account that never requested deletion is `active`.

The adapter is `PdoAccountDataRepository`, behind the
`AccountDataRepository` port. It is not a new persistence layer: every
statement reads or deletes one of the existing `social_*` tables, plus the
single `social_account_deletion` tombstone that must outlive them. The host
assembly (`Application::forHost`) supplies the PDO connection; the in-memory
assembly has no durable account data and the routes honestly answer
`503 account_data_unavailable`.

The HTTP gate is `RejectDeletedAccountWrites`: a non-read request for a
frozen or erased local user is rejected with `403
account_deletion_in_progress` or `410 account_deleted`, except for the deletion
route itself and reads. The job worker applies the same guard to account
subjects before invoking a handler, and deletion cancels matching queued and
reserved rows. A request or handler that passed the check just before the
freeze is swept once more after it returns (`AccountDeletionSweeper`), so its
late rows and blobs do not survive the deletion. This is what keeps an in-flight
request or job from leaving new account data after the freeze.

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
not deleted. New jobs carry the subject envelope and are cancelled by a
deletion; legacy payload-only queue rows remain explicitly unattributable, so
the export still names `social_job` rather than silently omitting them.

## Consequences

A host gets a real export/deletion boundary without copying the data model.
The archive is a stable machine-readable format, but it is not a portable
ActivityPub or AT Protocol export: it preserves the core's storage rows. A
host that wants an import format must define one on top of this archive.

The host must delete its account, credentials and session after a successful
core erasure. That is the remaining, deliberately host-owned part of "delete
my account"; the core cannot address a table it does not own. The tombstone is
kept after `erased` so the result stays inspectable and a repeated DELETE no
longer relies on an empty database looking like success.

An existing installation upgrades with `JobSchema::upgrade($connection)` and
`AccountDeletionSchema::upgrade($connection)`. Both are idempotent catalogue
checks, not a second migration runner: the host still decides when to run
them. They add the subject columns and index to the existing queue and create
the tombstone table without removing pending jobs. Legacy payload-only jobs
remain unattributable because their payload is not a reliable owner signal.
