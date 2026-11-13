# Retention cleanup

Version `0.6.0` adds a core-owned cleanup job for the three pieces of state
that otherwise grow without bound: orphan media attachments, read
notifications and the remote-thread cache. It is a **worker operation**, not an
HTTP route. A host schedules it, authenticates the worker in its own way and
runs it with the same `Application` it uses for requests.

The service is `AlohaSocial\Core\Service\Cleanup\CleanupService`; the worker
handler is `CleanupJobHandler` and the job type is `cleanup`. A host can call the
service directly for one run or enqueue the job for its normal worker. No
public deletion endpoint exists, so a browser cannot trigger a cleanup and no
new authentication surface was added.

## What is eligible

Retention is measured in whole days from the run time. The cutoff is strict:
a row is eligible only when its timestamp is **before** the cutoff
(`<`, not `<=`). Every run uses the clock passed to `Application`.

| Kind | Table | Eligibility | Default |
| --- | --- | --- | --- |
| `media` | `social_attachment` | `created_at < cutoff` **and** no row in `social_post_attachment` or `social_remote_post_attachment` references the id | 7 days |
| `notifications` | `social_notification` | `read_at IS NOT NULL` **and** `read_at < cutoff` | 30 days |
| `threads` | `social_remote_thread_post` | `fetched_at < cutoff` | 7 days |

The rules are deliberately narrow:

- An attachment linked to any local or remote post is never removed, including
  a soft-deleted post, a remote-post attachment row and another post that
  reused the same remote source URL. Only genuinely unreferenced uploads are
  deleted.
- An unread notification is never removed by age. Its `created_at` does not
  matter; only the read marker does. A notification that is read after the
  cutoff stays.
- Thread rows are a context **cache**. Removing one never touches a local
  post, a remote post, an attachment or a timeline row. The next context
  request refetches and repopulates it.

The media check is re-evaluated when the row is deleted. A link created
between the candidate query and the delete makes the conditional delete a
no-op, so the row survives. A soft-deleted post still counts as a link.

## Payload and defaults

The job payload is validated by `CleanupOptions::fromArray()`:

```json
{
  "kind": "all",
  "media_retention_days": 7,
  "notification_retention_days": 30,
  "thread_retention_days": 7,
  "limit": 100,
  "dry_run": false
}
```

| Field | Values | Default |
| --- | --- | --- |
| `kind` | `media`, `notifications`, `threads`, `all` | `all` |
| `media_retention_days` | integer 1–3650 | 7 |
| `notification_retention_days` | integer 1–3650 | 30 |
| `thread_retention_days` | integer 1–3650 | 7 |
| `limit` | integer 1–1000, applied **per kind** | 100 |
| `dry_run` | boolean | `false` |

Unknown keys are rejected rather than ignored. String numbers are rejected;
the payload is machine-written by the host, not a form. A dry run counts and
reports candidates but deletes nothing and does not call the blob store.

Rows are selected oldest first (`created_at`/`read_at`/`fetched_at`, then the
primary key) and limited per kind. `limit` is a budget for one run, not a
promise that everything is gone: the report includes the total `matched` and
the remaining count (`matched - deleted`). Run the job again to continue.

## Running it

Enqueue the job through the host's durable queue:

```php
use AlohaSocial\Core\Jobs\JobType;

$app->queue->push(JobType::CLEANUP, [
    'kind' => 'all',
    'media_retention_days' => 7,
    'notification_retention_days' => 30,
    'thread_retention_days' => 7,
    'limit' => 100,
]);
```

The host's existing worker loop then calls `runOnce()`. This is the same
worker that delivers federation jobs; the core does not install a timer. A
cron entry (for example every hour) is enough:

```cron
17 * * * * php /srv/alohasocial/bin/worker.php
```

For an operator-triggered dry run, call the service directly:

```php
use AlohaSocial\Core\Service\Cleanup\CleanupOptions;

$report = $app->cleanup->run(CleanupOptions::fromArray([
    'dry_run' => true,
    'limit' => 1000,
]));

echo json_encode($report->toArray(), JSON_PRETTY_PRINT);
```

`Application::forHost()` always assembles a `CleanupService`; `$app->cleanup`
is nullable only on the public type for older assemblies. A host that has not
created the tables must not run media or notification cleanup against them:
the PDO repositories throw a `DatabaseException`, which the worker treats as a
retryable failure.

## Report shape

```json
{
  "run_at": "2026-11-13T10:00:00Z",
  "dry_run": false,
  "matched": 3,
  "deleted": 3,
  "remaining": 0,
  "results": [
    {
      "kind": "media",
      "cutoff": "2026-11-06T10:00:00Z",
      "matched": 1,
      "deleted": 1,
      "remaining": 0,
      "dry_run": false
    }
  ]
}
```

`results` contains one entry per selected kind. The media entry can contain
`skipped_reason: "cleanup_media_unavailable"` when no `BlobStore` was supplied.
The report contains no user ids, attachment paths or notification content.

## Idempotence and failures

- A repeat run finds nothing after the first run, because the rows are gone.
- `deleteIfUnattached()` is a conditional delete. Two workers racing on the
  same attachment produce one winner; the loser does not remove the file of the
  winner.
- Read-notification and thread deletes use the primary key in a bounded
  subquery. A concurrent delete simply reduces the affected row count.
- Blob deletion happens after the metadata row commits. If the blob store
  fails, the row is already gone and the orphan object is left for the host's
  storage sweep; the failure is logged as `cleanup_blob_delete_failed` and
  does **not** retry a deleted row. This ordering means a crash can create an
  orphan blob, but it cannot leave a metadata row pointing at bytes another
  worker has removed.
- An invalid job payload is logged and the job is **completed**, not retried
  forever. A malformed cron entry should be visible in the logs, not fill the
  dead-letter queue.
- Database and storage exceptions propagate out of the handler, so the normal
  queue backoff and dead-letter policy applies.
- No attachment row is deleted when its blob store is not configured. The media
  result is skipped with `cleanup_media_unavailable`; notifications and thread
  caches still run.

The job payload reasons are stable:

| Reason | Meaning |
| --- | --- |
| `cleanup_invalid_kind` | `kind` is not `media`, `notifications`, `threads` or `all` |
| `cleanup_invalid_retention` | A retention value is not an integer in 1–3650 |
| `cleanup_invalid_limit` | `limit` is not an integer in 1–1000 |
| `cleanup_invalid_dry_run` | `dry_run` is not a boolean |
| `cleanup_unknown_parameter` | The payload contains an unknown key |
| `cleanup_media_unavailable` | No `BlobStore`; media cleanup was skipped |
| `cleanup_blob_delete_failed` | A blob could not be deleted after its row was removed |

The first five are job-payload validation errors and never become HTTP
responses. The last two are log/report reasons. The HTTP reference has no new
status code because this feature adds no route.

## Database and host setup

There is **no new table and no migration** for this slice. It reuses:

- `social_attachment` and `social_post_attachment` from `AttachmentSchema` and
  `PostSchema`;
- `social_remote_post_attachment` from `RemotePostSchema` (the media candidate
  query checks both link tables, so a host that imports remote media must
  create it before enabling media cleanup);
- `social_notification` with the nullable `read_at` column and its unread
  index from `NotificationSchema`;
- `social_remote_thread_post` from `RemoteThreadSchema`.

A fresh host creates those schemas as before. An existing host that already
serves media, notifications and remote threads already has them. If it has an
older notification table without `read_at`, add the column and index first
(see the [database guide](database.md)); the cleanup job treats rows with
`read_at IS NULL` as unread and leaves them alone.

`PdoAttachmentRepository::unattachedBefore()` and
`countUnattachedBefore()` use `NOT EXISTS` against both
`social_post_attachment` and `social_remote_post_attachment`. A host that
creates `social_attachment` but not those link tables must not enable media
cleanup: the query fails with a database error. This is the same dependency as
the media routes that attach media to local posts, plus the remote-media path
when it is used.

The queries are portable SQL over the existing `Connection` port. There is no
schema change and no data backfill. The cleanup job does not need a separate
database user or elevated privileges beyond the normal `DELETE` permission on
those tables.

## Tests and demo

- `tests/Service/Cleanup/CleanupServiceTest.php` covers dry run, real run,
  linked/fresh/unread retention, per-kind limits, idempotence, missing blob
  store and terminal invalid payloads.
- `tests/Service/Cleanup/CleanupOptionsTest.php` covers defaults and every
  payload reason.
- The attachment, notification and remote-thread contract tests cover the new
  repository methods against both in-memory and SQLite adapters.
- `tests/ApplicationHostTest.php` runs the job through `Application::forHost()`
  and checks the SQL rows and blobs after the worker.
- `bin/core-demo` seeds expired and fresh rows, prints a dry-run report,
  enqueues the real job, and asserts that exactly the expired media,
  notification and thread rows were removed while linked, fresh and unread
  rows remain.
