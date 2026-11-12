# Database

The core talks SQL through `Platform\Db\Connection`; it never touches PDO
directly. The standalone host constructs `PdoConnection` from its own PDO
handle, the Nextcloud host does the same with Nextcloud's.

## The port

| Method | Returns |
| --- | --- |
| `execute($sql, $params)` | affected rows |
| `fetchAll($sql, $params)` | list of associative rows |
| `fetchOne($sql, $params)` | one row or null |
| `fetchValue($sql, $params)` | first column of the first row or null |
| `transaction(callable)` | the callable's result |
| `driver()` | `sqlite`, `mysql`, `pgsql`, … |

Named parameters are written without a prefix in the array (`['id' => …]`) and
may be written with one in the SQL (`:id`); the connection adds the prefix when
binding. Booleans become 0/1, `DateTimeImmutable` becomes the canonical
timestamp, and anything else is refused.

## Schemas

Each table's definition lives next to its repository or adapter:

- `src/Domain/Post/PostSchema.php` → `social_post` and `social_post_attachment`
- `src/Domain/Media/AttachmentSchema.php` → `social_attachment` (including nullable `source_url` and its unique index)
- `src/Domain/Federation/FollowerSchema.php` → `social_follower` (including nullable `shared_inbox_url`)
- `src/Domain/Federation/FollowingSchema.php` → `social_following`
- `src/Domain/Federation/RemotePostSchema.php` → `social_remote_post` and `social_remote_post_attachment`
- `src/Domain/Federation/RemoteThreadSchema.php` → `social_remote_thread_post`
- `src/Domain/Federation/BlockSchema.php` → `social_block`
- `src/Jobs/JobSchema.php` → `social_job`
- `src/Domain/Federation/ActivityLogSchema.php` → `social_activity_log`
- `src/Domain/Atproto/AtprotoPublicationSchema.php` → `social_atproto_publication`

Both hosts create the same columns. The standalone runs the statements from its
migration step; the Nextcloud host maps them onto its schema API. A dump can
therefore move between the two databases.

Adding a column means changing the schema class **and** both hosts' migration
paths in the same release. There is no automatic migration runner in the core:
it would need to know the host's migration mechanism, which is exactly the kind
of host concern ports exist to keep out. Existing follower tables need the
nullable `social_follower.shared_inbox_url VARCHAR(512)` column; rows with
`NULL` keep delivering to `inbox_url`, so no backfill is required. See
[shared inboxes](shared-inboxes.md).

`social_job` is the durable queue used by `PdoJobQueue`; `social_activity_log`
is the replay guard used by `PdoActivityLog`. The queue reserves a row with a
conditional update in a transaction, and the activity log's primary key is the
claim. Both rely on the database, not on a process-local cache, so a restart or
a second worker does not lose a delivery or accept a replayed activity.

`social_remote_post` stores signed `Create` and `Announce` content from
followed remote actors. Its primary key is the activity id, which makes a
duplicate delivery impossible even after the 24-hour activity-log window. It
keeps the object id, object author, signing actor, kind (`post`/`boost`),
content, optional content warning, published time and `deleted_at`. The home
timeline joins the table to `social_following`; remote rows are shared by all
local followers and the join, rather than a per-user copy, scopes visibility.
`social_remote_post_attachment` is the ordered link between a remote activity
and imported local attachment rows; the attachment source URL is unique, so
one remote image is stored once and reused.

`social_remote_thread_post` is the on-demand context cache used by
`GET /api/v1/remote-posts/{id}/thread`. Its primary key is the remote object id,
not an activity id: an ancestor or reply may never have been delivered to this
instance. It stores the author, content, optional content warning, publication
time, `in_reply_to_id` and `fetched_at`. The parent and fetched indexes support
direct-child queries and a host's retention job. Upserts by object id make a
repeat or a shared ancestor idempotent; `deleteFetchedBefore()` is the only
cleanup API and is not scheduled by the core. Rows are deliberately not shown
in the home timeline and do not import media blobs.
`social_block` is unique by (local user, actor) and is applied to the same
home query with a `NOT EXISTS`, so a block hides an actor only for the reader
who created it; see [blocks](blocks.md).

`social_atproto_publication` stores the idempotency mapping from a local post
to the AT Protocol record written for one account. Its primary key is
`(local_user_id, post_id)` and `(local_user_id, rkey)` is unique; `rkey` is
the deterministic TID, `uri` is the PDS-returned `at://` URI and
`published_at` is the canonical timestamp. The row is written only after the
PDS accepted the record, so a failed publish leaves no partial row. A local
post deletion queues `atproto.delete`, calls `com.atproto.repo.deleteRecord`
and removes this row only after the PDS accepted it; a failed delete leaves
the row for the normal retry. `removeForPost()` remains the cleanup hook for a
host that deliberately drops a mapping. No new table or migration is added for
remote delete. OAuth sessions are not stored here either: the access and
refresh tokens are host credentials, and `AtprotoSessionStore` keeps them in
the host's secret store. The core's only session adapter is the in-memory fake
for tests. See [AT Protocol publishing](atproto.md).

`social_attachment` stores only metadata; the original and thumbnail bytes live
under the keys in the host's `BlobStore`. `source_url` is nullable for local
uploads and unique for imported remote media. `social_post_attachment` is the
ordered join between a local post and its attachments; both are written by the
post insert transaction. A remote post uses
`social_remote_post_attachment` instead. `PdoAttachmentRepository::findMany`
deliberately does not promise SQL row order; callers such as the presenter
order the attachments by the position list. See [media](media.md).

## Transactions and uniqueness

`PdoJobQueue::reserve()` uses `transaction()` to select due rows and update the
ones it wins. The conditional `UPDATE` is what makes a concurrent reservation
safe; a select followed by an unconditional update would not be.
`PdoActivityLog::claim()` deliberately does not use a select-then-insert: the
`activity_id` primary key rejects the duplicate, and the adapter checks the row
after a rejected insert so a real database error is not reported as a replay.

## Timestamps

Every stored timestamp is UTC with second precision, in the fixed-width form
`2026-11-11T18:14:00Z` (`Support\Time`). Two consequences:

1. lexicographic order equals chronological order on SQLite, PostgreSQL and
   MySQL — no timezone conversion in a query;
2. a value that made a round trip equals the value that was written, which the
   tests assert.

Never store a local time or a substring of `NOW()`. Never compare a stored
string with a non-canonical one.

## Ordering and cursors

Lists order by `timestamp DESC, id DESC`. The id breaks ties so pagination is
stable when two rows share a second: without it, the same row could appear on
two pages. `before` is exclusive (`<`), which is what makes "give me the next
page" unambiguous.

## Transactions

`transaction()` joins nested calls: only the outermost commits, and an
exception at any depth marks the whole chain rollback-only, even if an inner
caller catches it. Partial commits are never what a caller meant.

## Portability notes

- Index key length: MySQL with utf8mb4 allows 3072 bytes; `(local_user_id,
  actor_id)` at 255 + 255 characters fits, `(visibility, published_at)` and
  `(author_id, published_at)` are smaller still. The remote-post primary key
  is 512 characters (2048 bytes at utf8mb4) and its object index is
  512 + 16 characters, both below the limit.
- `LIMIT` is inlined as an integer, never bound: some drivers refuse a bound
  `LIMIT`, and the value is a clamped integer from the service.
- `INSERT`/`UPDATE` avoid `RETURNING` and upsert syntax; both differ across the
  three supported databases. Uniqueness is a database guarantee, the service
  checks first for a friendly answer. The activity log uses a plain `INSERT`
  and treats the resulting duplicate-key error as the replay answer.
- `social_job` reserves with `UPDATE ... WHERE id = ... AND (reserved_at IS
  NULL OR reserved_at <= ...)`, which works on SQLite, PostgreSQL and MySQL
  without vendor-specific locking syntax.
