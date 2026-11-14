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
| `transaction(callable)` | the callable's result (see also `TransactionRunner`) |
| `driver()` | `sqlite`, `mysql`, `pgsql`, … |

`TransactionRunner` is the narrow service-facing transaction port:

```php
interface TransactionRunner
{
    public function transaction(callable $work): mixed;
}
```

`PdoConnection` implements both `Connection` and `TransactionRunner`, so
`Application::forHost()` detects it automatically. A host with a non-PDO
adapter should implement `TransactionRunner` on that adapter (or pass a
dedicated implementation as `transactions:`); without one the post service
runs its writes directly, which is only appropriate for the in-memory
assembly. Nested calls join the outer transaction, and an exception at any
depth rolls the whole chain back.

Named parameters are written without a prefix in the array (`['id' => …]`) and
may be written with one in the SQL (`:id`); the connection adds the prefix when
binding. Booleans become 0/1, `DateTimeImmutable` becomes the canonical
timestamp, and anything else is refused.

## Schemas

Each table's definition lives next to its repository or adapter:

- `src/Domain/Post/PostSchema.php` → `social_post`, `social_post_attachment`, ordered `social_post_recipient`, normalized `social_post_hashtag` rows and the `social_post_revision` history of edits
- `src/Domain/Post/PollSchema.php` → `social_poll`, ordered `social_poll_option` and `social_poll_vote` (one row per voter and poll; sorted zero-based positions)
- `src/Domain/Post/FavouriteSchema.php` → `social_favourite` (per-user post favourites, keyed by user and post)
- `src/Domain/Filter/FilterSchema.php` → `social_filter` (one per-reader keyword rule with action, JSON contexts and keyword list; no shared row is ever rewritten by another reader)
- `src/Domain/Scheduling/ScheduledPostSchema.php` → `social_scheduled_post` (pending, publishing, published, cancelled and failed writes, with a reserved post id whose unique key makes retries idempotent)
- `src/Domain/Interaction/ReactionSchema.php` → `social_reaction` (inbound and outbound Like/EmojiReact state, keyed by direction, subject, object, kind and emoji; unique key is the reaction identity)
- `src/Domain/Post/BookmarkSchema.php` → `social_bookmark` (private per-user bookmarks, keyed by user and post)
- `src/Domain/Federation/MuteSchema.php` → `social_mute` (private per-user mutes of local accounts or remote actor URLs, with a nullable expiry)
- `src/Domain/Timeline/AccountListSchema.php` → `social_account_list` and `social_account_list_member` (private lists and their local account membership)
- `src/Domain/Timeline/FollowedHashtagSchema.php` → `social_followed_hashtag` (private per-user followed tags)
- `src/Domain/Post/BoostSchema.php` → `social_boost` (local reblogs, unique by user and post, with an Announce-compatible id)
- `src/Domain/Notification/NotificationSchema.php` → `social_notification` (local favourites, follows, mentions and reblogs, keyed by a deterministic event id, with a nullable `read_at` marker)
- `src/Domain/Media/AttachmentSchema.php` → `social_attachment` (including nullable `source_url` and its unique index)
- `src/Domain/Federation/FollowerSchema.php` → `social_follower` (including nullable `shared_inbox_url` and `is_local`)
- `src/Domain/Federation/FollowingSchema.php` → `social_following` (outgoing follow state `pending`/`accepted`/`rejected` and the exact Follow activity id used by Accept/Reject)
- `src/Domain/Federation/RemotePostSchema.php` → `social_remote_post` (including nullable `updated_at`, bounded `object_type` and optional `object_metadata` JSON) and `social_remote_post_attachment`
- `src/Domain/Federation/PendingRemoteUpdateSchema.php` → `social_pending_remote_update` (bounded, expiring queue of `Update` activities whose post has not arrived yet)
- `src/Domain/Federation/RemoteThreadSchema.php` → `social_remote_thread_post`
- `src/Domain/Federation/BlockSchema.php` → `social_block`
- `src/Jobs/JobSchema.php` → `social_job`
- `src/Domain/Federation/ActivityLogSchema.php` → `social_activity_log`
- `src/Domain/Atproto/AtprotoPublicationSchema.php` → `social_atproto_publication`

Both hosts create the same columns. The standalone runs the statements from its
migration step; the Nextcloud host maps them onto its schema API. A dump can
therefore move between the two databases.

`social_post` has nullable `in_reply_to_id` and an index on
`(in_reply_to_id, published_at)`. A direct post's local audience is stored in
`social_post_recipient` as ordered `(post_id, recipient_id, position)` rows;
both PDO reads (hydrating a post and the reader's own/direct home timeline)
use those rows, so the audience is not duplicated in a JSON column. Existing
hosts add the nullable column and create the recipient table before enabling
direct posts or replies; rows without a reply/audience need no backfill.

Adding a column means changing the schema class **and** both hosts' migration
paths in the same release. For `social_following`, add
`state VARCHAR(16) NOT NULL DEFAULT 'accepted'` and nullable
`follow_activity_id VARCHAR(512)` plus its unique index; old rows remain
accepted, and the write path inserts pending rows. There is no automatic
migration runner in the core:
it would need to know the host's migration mechanism, which is exactly the kind of host concern ports exist to keep out. Existing follower tables need the
nullable `social_follower.shared_inbox_url VARCHAR(512)` column; rows with
`NULL` keep delivering to `inbox_url`, so no backfill is required. See
[shared inboxes](shared-inboxes.md). For the Mastodon-compatible local follow
routes, add `social_follower.is_local SMALLINT NOT NULL DEFAULT 0`; existing
rows stay remote (`0`) and need no backfill. Local edges are excluded from
`inboxesFor()`, and an unfollow removes the row.

`social_job` is the durable queue used by `PdoJobQueue`; `social_activity_log`
is the replay guard used by `PdoActivityLog`. The queue reserves a row with a
conditional update in a transaction, and the activity log's primary key is the
claim. Both rely on the database, not on a process-local cache, so a restart or
a second worker does not lose a delivery or accept a replayed activity.
`complete()` and `deadLetter()` additionally require `reserved_at IS NOT NULL`: a
stale worker cannot delete a pending row or dead-letter a job it does not own.

A post write is one unit of work when a `TransactionRunner` is configured:
the `social_post` row, ordered attachment/hashtag/recipient rows, the poll and
its options, the scheduled AT Protocol publication and every federation
delivery job commit or roll back together. A failed attachment link, hashtag
insert, poll option, AT Protocol job or queue push therefore leaves no visible
post and no orphaned job. Notification writes are the one deliberate
exception: a successful notification insert joins the transaction, but
`NotificationService` treats a missing or failing notification table as
best-effort, logs it and does not undo the primary post. The deterministic
notification id remains the dedupe boundary, so a retry repairs a missed row
without creating a second one. Deleting a post wraps the soft delete, the AT
Protocol delete job and the delivery jobs in the same boundary.

`social_remote_post` stores signed `Create` and `Announce` content from
followed remote actors. Its primary key is the activity id, which makes a
duplicate delivery impossible even after the 24-hour activity-log window. It
keeps the object id, object author, signing actor, kind (`post`/`boost`),
object type, typed metadata JSON, content, optional content warning,
published time and `deleted_at`. The metadata JSON is decoded leniently on
read, so a malformed or older value degrades the card instead of hiding the
row. The home
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
direct-child queries and the retention job. Upserts by object id make a repeat
or a shared ancestor idempotent; the core `cleanup` job removes rows by
`fetched_at` and `deleteFetchedBefore()` remains the lower-level API. Rows are
deliberately not shown in the home timeline and do not import media blobs.
`social_poll` is keyed by `post_id` and stores the two flags, the canonical
`expires_at` and the aggregate counts; `social_poll_option` stores the ordered
titles (`position` 0-based) and their counts. Both are written by
`PdoPostRepository::insert()` inside the existing post transaction, so a
rejected poll leaves no post and a failed post insert leaves no poll rows. The
repository loads a page of polls in one query for the poll rows and one for the
option rows, then attaches them to their posts; there is no per-post query.
`expired` is derived from `expires_at` and the clock, so no expiry row or job
is needed. A post soft delete keeps the poll rows for the host's cleanup, just
like attachments and favourites; a hard delete should remove the option rows
and the poll row in that order (the core schema adds no foreign key). An
existing host must create both tables with `PollSchema::statements()` before
serving a status whose repository reads them or accepting a `poll` parameter.
`social_poll_vote` is keyed by `(post_id, user_id)` and stores the viewer's
sorted zero-based positions. `PdoPollVoteRepository::insert()` writes the row
and advances the poll and option counters in one transaction; a duplicate key
is the race-safe no-second-vote boundary. Status and poll reads degrade to
`voted: false` until the table exists, while the vote route answers `503
polls_not_configured`; these writes are not imported from a legacy host.
`social_block` is unique by (local user, actor) and is applied to the same
home query with a `NOT EXISTS`, so a block hides a remote actor only for the
reader who created it. The local-follow half filters the same table by the
derived actor URL; see [blocks](blocks.md). `social_favourite` is unique by
(local user, post) and stores the creation time. The composite primary key
makes a repeat favourite idempotent, the post index serves public counts and
the `(user, created_at)` index supports a future favourites timeline. A post
deletion is a soft delete and does not cascade here; the host may remove
orphaned favourites on its own cleanup schedule. `social_bookmark` uses the
same composite key and also stores `created_at`; it is private, so there is no
	public count. Its `(user_id, created_at, post_id)` ordering supports the
newest-first bookmark list and its exclusive cursor. Bookmarks are not
notifications and do not federate; a host may prune rows for soft-deleted
posts on the same schedule as favourites. `social_mute` is unique by
(local user, actor), where the actor is a local account id or an absolute
remote actor URL, and stores the notification flag plus a nullable
`expires_at`. Reads filter expired rows with `expires_at > now`, so a short
mute stops hiding content without a cleanup job; the home query and the
notification count both use the same table. There is no public count and no
federation activity. `social_account_list` stores a private list per local
user with its title, replies policy and exclusive flag; the
`(user_id, created_at, id)` index serves the owner's newest-first list.
`social_account_list_member` stores ordered `(list_id, account_id,
created_at)` rows with a composite primary key, so adding an existing member
is idempotent and membership is shared across renames. It contains only known
local account ids; deleting a list deletes its member rows, while an account
deletion is a host cleanup concern. The exclusive flag is read by the
local-follow home timeline to hide members' posts and boosts, not by a second
follow table. `social_followed_hashtag` stores one normalized tag per local
user with the original `created_at`; `(user_id, hashtag)` is the primary key,
so following twice keeps the first timestamp, and `(user_id, created_at,
hashtag)` serves the newest-first tag page. The core enforces a 100-tag limit
per account so the followed-tag timeline query stays bounded. The table is
private, does not federate and has no backfill; the timeline joins it against
`social_post_hashtag`, so a host that imported posts without hashtag rows
needs to backfill those rows before the followed-tag timeline can see them.

Search has no table of its own: `PdoPostRepository` implements
`PostSearchRepository` over the existing `social_post` rows, and the host's
local account directory may implement `LocalActorSearch`. Account search does
not add a user table to the core. A large host can add a database index on its
content column or replace the adapter behind the port; the core route needs no
schema migration.

`social_notification` stores one local event per recipient, type, actor and
optional post. Its primary key is a deterministic hash of that tuple, so a
repeated favourite or Follow cannot create a second row; no random id or
secondary idempotency table is involved. The `(recipient_id, created_at, id)`
index serves the newest-first page and its id tie-break; the
`(recipient_id, read_at, created_at, id)` unread index serves the filtered
list and count. `read_at` is null until the recipient dismisses one row or
clears all unread rows, and the list omits rows with a marker. A notification
is historical: unfavouriting, unfollowing or deleting the post does not delete
it. A deleted post is omitted from the projected notification. The core
cleanup job removes only **read** rows whose `read_at` is older than the
configured retention; unread rows are never eligible. Existing listing-only
hosts must add the nullable column and unread index before the read routes
work; old rows remain unread and are therefore not removed by the cleanup job.

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

Instance metadata and statistics add no table. The public responses read the
`social/instance.*` configuration and, when available, count through the
optional `LocalActorCount`, `PostCount` and `RemoteDomainCount` capabilities on
the existing directory and post/remote-post repositories. `PostCount` filters
`social_post.deleted_at IS NULL`; `RemoteDomainCount` derives distinct hosts
from live `social_remote_post.actor_id` rows. A host that omits a capability
reports `0`; nothing needs to be backfilled, migrated or cleaned up.

`social_attachment` stores only metadata; the original and thumbnail bytes live
under the keys in the host's `BlobStore`. `source_url` is nullable for local
uploads and unique for imported remote media. `social_post_attachment` is the
ordered join between a local post and its attachments; both are written by the
post insert transaction. A remote post uses
`social_remote_post_attachment` instead. `PdoAttachmentRepository::findMany`
deliberately does not promise SQL row order; callers such as the presenter
order the attachments by the position list. See [media](media.md).

### Retention cleanup

Version `0.7.0` adds `TransactionRunner` and the post transaction boundary;
it adds no table or column, so an existing schema needs no migration. Version
`0.6.0` adds the `cleanup` job and `CleanupService`. It adds no table
and no migration; it deletes rows through the existing schemas:

```sql
-- media: metadata only; no local or remote-post link may reference the id
DELETE FROM social_attachment
WHERE id = :id
  AND NOT EXISTS (SELECT 1 FROM social_post_attachment WHERE attachment_id = :id)
  AND NOT EXISTS (SELECT 1 FROM social_remote_post_attachment WHERE attachment_id = :id);

-- notifications: read marker is required and must be older than the cutoff
DELETE FROM social_notification
WHERE id IN (SELECT id FROM social_notification
  WHERE read_at IS NOT NULL AND read_at < :cutoff
  ORDER BY read_at ASC, id ASC LIMIT :limit);

-- thread cache: fetched_at is the only age signal
DELETE FROM social_remote_thread_post
WHERE object_id IN (SELECT object_id FROM social_remote_thread_post
  WHERE fetched_at < :cutoff
  ORDER BY fetched_at ASC, object_id ASC LIMIT :limit);
```

The attachment query is rechecked by `deleteIfUnattached()` at delete time. A
soft-deleted post still has its link row and therefore protects the attachment.
The attachment candidate and delete queries check both `social_post_attachment`
and `social_remote_post_attachment`, so a shared remote image survives even
when no local post references it. The cleanup service deletes both blob keys
after the metadata delete commits;
a blob failure is logged as `cleanup_blob_delete_failed` and leaves an orphan
object for the host's storage sweep, never a row that points at missing bytes.
Notifications require `read_at`, so unread events survive regardless of age.
Thread rows are a cache and do not touch remote posts or media. The
`limit` is per kind, bounded to 1000, and the next run continues with the next
oldest page. See [retention cleanup](../guides/retention-cleanup.md).

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
