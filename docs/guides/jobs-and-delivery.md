# Jobs and delivery

Federation is asynchronous: a post is stored and returned to the user before
any peer is contacted. The queue is the boundary between those two facts.

## Contract

```php
interface JobQueue
{
    public function push(string $type, array $payload, ?DateTimeImmutable $availableAt = null): Job;
    public function reserve(int $limit, DateTimeImmutable $now): array;
    public function complete(Job $job): void;
    public function fail(Job $job, Throwable $error, DateTimeImmutable $now): void;
    public function deadLetter(Job $job, Throwable $error, DateTimeImmutable $now): void;
}
```

- `push` stores a job that is due now (or at a given time) and returns it with
  a stable id. An empty type throws `InvalidJobException` with the stable code
  `invalid_job_type`.
- `reserve` hands out due jobs; a reserved job is not handed out twice.
- `complete` removes it. The PDO adapter only removes a row that is currently
  reserved, so a stale worker cannot delete an unreserved job.
- `fail` returns it with a later `availableAt`, or dead-letters it once the
  retry ceiling is reached.
- `deadLetter` records a terminal failure immediately. It never schedules
  another attempt. Adding this method to `JobQueue` is a breaking change for a
  custom host adapter.

`InMemoryJobQueue` is the reference implementation. `PdoJobQueue` applies the
same rules against `social_job`: `MAX_ATTEMPTS = 5`, first retry after 30 s,
doubling to a 3600 s ceiling. Its reservation TTL is
`PdoJobQueue::RESERVATION_SECONDS = 300`, so a worker that dies after reserving
does not strand the job.

| Attempt | Next try |
| --- | --- |
| 1 | +30 s |
| 2 | +60 s |
| 3 | +120 s |
| 4 | +240 s |
| 5 | dead letter |

## Durable queue

```php
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\PdoJobQueue;

JobSchema::create($connection);
$queue = new PdoJobQueue($connection, $clock, $ids);
```

`PdoJobQueue` stores each job in `social_job`, reserves with a conditional
update inside a transaction, and implements `complete()`/`fail()`/
`deadLetter()` in SQL. `complete()` and `deadLetter()` require the row to still
be reserved; an unreserved or already finished job is a no-op. A new process or
connection sees the same pending jobs. The columns and indexes are in
`JobSchema::statements()`; a host applies that DDL and passes the adapter to
`Application::forHost()`. Tests may keep `InMemoryJobQueue`.

## Worker

`JobWorker::runOnce($limit)` reserves a batch, runs each job through
`JobHandlerRegistry`, and **continues after a failure**: the outcome is logged,
the rest of the batch still runs. The caller decides the cadence —
cron every minute, a systemd service in a loop, or a test calling it directly.

The worker separates three outcomes:

| Outcome | Queue call | Log code | Retried? |
| --- | --- | --- | --- |
| Handler throws a `TerminalJobException` | `deadLetter()` | the exception's code, e.g. `invalid_job_payload` or `unknown_job_type` | no |
| Handler throws anything else | `fail()` | `job_handler_failed` | yes, with backoff |
| Handler succeeded, but removing the reservation throws | none | `job_completion_failed` | no handler rerun |

The completion case is deliberate. The side effect already happened; calling
`fail()` would make the queue run the handler again and duplicate it. The
reservation stays in place, and the durable queue's TTL lets a later worker
recover it. The logged error is the operator's signal that completion needs
attention.

## Handlers

A handler is `handle(Job $job): void`. It may throw; the queue owns the retry
policy, not the handler. `FederationDeliveryHandler` distinguishes:

- transport failure (`HttpClientException`) → retry;
- 5xx or 429 → retry;
- 4xx other than 429 → also retried today, because a peer may be misconfigured
  temporarily; if that turns out to be wrong, the change belongs here;
- malformed stored payload (`InvalidJobPayloadException`) or an unknown job
  type (`UnknownJobTypeException`) → terminal: the worker dead-letters it at
  once, without spending the remaining attempts on input that cannot change.

A terminal exception implements `TerminalJobException` and exposes a stable
`errorCode()`. A handler that knows its input is permanently invalid should
throw one of these rather than a generic exception.

## Shared-inbox batching

`FollowerInboxResolver` asks the repository for one effective inbox per
follower: `shared_inbox_url` when present, otherwise `inbox_url`. Duplicate
effective URLs collapse before jobs are written, so one post to two followers
on the same server is one job to that server's shared inbox. The payload and
handler stay one-inbox/one-activity; batching happens at the inbox selection,
not by inventing a multi-recipient request. Follow, Undo and Accept always use
the individual inbox. See [shared inboxes](shared-inboxes.md).

## Follow and Undo jobs

Outgoing follows use `federation.follow`, not the generic `federation.deliver`.
The payload carries `operation` (`follow` or `undo`), `follow_id`, `inbox`,
`activity` and `key_id`. A `follow` job re-reads the row before every attempt
and becomes a no-op if the row is gone or no longer pending; that is how an
Unfollow wins a race against a still-queued Follow. An `undo` job has no local
row because Unfollow removes it before the job runs. Both are ordinary retried
jobs: a non-2xx answer or transport failure leaves the same payload queued for
the next attempt, and the activity id derived from the follow row makes the
receiver's handling idempotent.

## Idempotency

A retry must be safe for the receiver. Two things make it so:

1. the activity id is derived from the post id, so the same post produces the
   same `Create` id on every attempt;
2. the payload is built once, at write time, not rebuilt at delivery time.

A peer that sees the same activity id twice can ignore the second one.

## Failure codes

`JobFailureCode` holds the stable log/operator codes. They are not HTTP
response codes.

| Code | Source | Terminal |
| --- | --- | --- |
| `invalid_job_type` | `push()` rejects an empty type with `InvalidJobException` | n/a (nothing was queued) |
| `invalid_job_payload` | A payload-specific `TerminalJobException` | yes |
| `unknown_job_type` | No handler is registered for the stored type | yes |
| `job_handler_failed` | Any other handler exception | no; normal retry |
| `job_completion_failed` | The handler succeeded but `complete()` threw | no retry; leave the reservation for TTL recovery |

See [error codes](../reference/error-codes.md#worker-and-queue-failure-codes)
for the log shape. A dashboard should match on these strings, not on the
human-readable message.

## Adding a job type

1. Add a case to `JobType`.
2. Implement `JobHandler`.
3. Register it in the host's `JobHandlerRegistry`.
4. Keep the payload JSON-serialisable and small; it is stored, not referenced.

Do not put a service object or a closure in a payload. The durable queue will
serialise it, and a closure cannot be serialised.
