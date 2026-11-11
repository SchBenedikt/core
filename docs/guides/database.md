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

Each table's definition lives next to its repository:

- `src/Domain/Post/PostSchema.php` → `social_post`
- `src/Domain/Federation/FollowerSchema.php` → `social_follower`

Both hosts create the same columns. The standalone runs the statements from its
migration step; the Nextcloud host maps them onto its schema API. A dump can
therefore move between the two databases.

Adding a column means changing the schema class **and** both hosts' migration
paths in the same release. There is no automatic migration runner in the core:
it would need to know the host's migration mechanism, which is exactly the kind
of host concern ports exist to keep out.

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
  `(author_id, published_at)` are smaller still.
- `LIMIT` is inlined as an integer, never bound: some drivers refuse a bound
  `LIMIT`, and the value is a clamped integer from the service.
- `INSERT`/`UPDATE` avoid `RETURNING` and upsert syntax; both differ across the
  three supported databases. Uniqueness is a database guarantee, the service
  checks first for a friendly answer.
