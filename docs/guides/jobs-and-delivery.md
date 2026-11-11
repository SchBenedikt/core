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
}
```

- `push` stores a job that is due now (or at a given time) and returns it with
  a stable id.
- `reserve` hands out due jobs; a reserved job is not handed out twice.
- `complete` removes it.
- `fail` returns it with a later `availableAt`, or dead-letters it.

`InMemoryJobQueue` implements the same rules as the durable queue must:
`MAX_ATTEMPTS = 5`, first retry after 30 s, doubling to a 3600 s ceiling.

| Attempt | Next try |
| --- | --- |
| 1 | +30 s |
| 2 | +60 s |
| 3 | +120 s |
| 4 | +240 s |
| 5 | dead letter |

## Worker

`JobWorker::runOnce($limit)` reserves a batch, runs each job through
`JobHandlerRegistry`, and **continues after a failure**: the job is logged and
handed back, the rest of the batch still runs. The caller decides the cadence —
cron every minute, a systemd service in a loop, or a test calling it directly.

## Handlers

A handler is `handle(Job $job): void`. It may throw; the queue owns the retry
policy, not the handler. `FederationDeliveryHandler` distinguishes:

- transport failure (`HttpClientException`) → retry;
- 5xx or 429 → retry;
- 4xx other than 429 → also retried today, because a peer may be misconfigured
  temporarily; if that turns out to be wrong, the change belongs here;
- malformed stored payload (`InvalidJobPayloadException`) → retry will never
  help, but the queue still counts attempts and eventually dead-letters it
  rather than dropping it silently.

## Idempotency

A retry must be safe for the receiver. Two things make it so:

1. the activity id is derived from the post id, so the same post produces the
   same `Create` id on every attempt;
2. the payload is built once, at write time, not rebuilt at delivery time.

A peer that sees the same activity id twice can ignore the second one.

## Adding a job type

1. Add a case to `JobType`.
2. Implement `JobHandler`.
3. Register it in the host's `JobHandlerRegistry`.
4. Keep the payload JSON-serialisable and small; it is stored, not referenced.

Do not put a service object or a closure in a payload. The durable queue will
serialise it, and a closure cannot be serialised.
