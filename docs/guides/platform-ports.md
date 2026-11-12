# Platform ports

A port is what the core needs from the world. There are five, all in
`src/Platform/`, and both hosts implement them. Adding a sixth is a breaking
change for both hosts, so the list stays short on purpose.

| Port | Methods | In-memory | Production |
| --- | --- | --- | --- |
| `Clock` | `now()` | `FrozenClock` | `SystemClock` |
| `Config` | `get`/`set`/`delete`/`has`/`all`, typed getters | `ArrayConfig` | host configuration |
| `Storage\BlobStore` | `put`/`read`/`exists`/`delete`/`size`/`list` | `InMemoryBlobStore` | `LocalBlobStore`, S3 later |
| `Db\Connection` | `execute`/`fetchAll`/`fetchOne`/`fetchValue`/`transaction`/`driver` | — | `PdoConnection` |
| `Http\HttpClient` | `request()` | `FakeHttpClient` (tests) | `CurlHttpClient` |

## What each one promises

### Clock

`now()` returns an immutable `DateTimeImmutable` in UTC. Nothing in the core
calls `time()` or `date()` for persisted or federated values, which is what
makes the tests deterministic: `FrozenClock` moves only when a test moves it.

### Config

A two-level store (`app`, `key`) with typed getters that take a default. The
core reads only `social/instance.url` and `social/instance.name`; a host may
store them in `config.php`, an `.env` file or the database. `Config` is not a
secret store — private keys belong in the host's secret handling, sealed with
`Security\SecretBox` when persisted.

### BlobStore

Keys are relative paths, never absolute, and `BlobPath` rejects empty segments,
`..` and backslashes before a driver sees them. A store may be backed by a
directory, an object bucket or memory, but `list($prefix)` must return sorted
paths that match by path segment, not by string prefix.

### Connection

A deliberately small SQL port: statements and bound parameters, no query
builder. `execute()` returns the affected row count, which is how a repository
tells "deleted" from "was not there". `transaction()` joins nested calls and
rolls the whole chain back if any level throws.

### HttpClient

One request method with method, URL, headers, body and timeout, returning a
status and body or throwing `HttpClientException` on a transport failure. A
non-2xx answer is **not** an exception — the delivery handler decides whether a
status is retryable.

## Contract tests

Four ports have a shared test case that every implementation must pass:

- `tests/Platform/Storage/BlobStoreTestCase.php`
- `tests/Domain/PostRepositoryTestCase.php`
- `tests/Domain/FollowerRepositoryTestCase.php`
- `tests/Domain/FollowingRepositoryTestCase.php`

A new adapter is done when it passes the existing case, not when its own tests
pass. That is the mechanism that keeps `InMemoryPostRepository` and
`PdoPostRepository` behaviourally identical.

The application-level `Jobs\JobQueue` and `ActivityLog` ports have their own
shared cases in `tests/Jobs/JobQueueTestCase.php` and
`tests/Domain/ActivityLogTestCase.php`; `PdoJobQueue` and `PdoActivityLog` run
those cases against SQLite as well. The remote-post and combined home-timeline
repositories have their own contracts in
`tests/Domain/RemotePostRepositoryTestCase.php` and
`tests/Domain/HomeTimelineRepositoryTestCase.php`, each run against the
in-memory and PDO adapters.

```php
final class S3BlobStoreTest extends BlobStoreTestCase
{
    protected function store(): BlobStore
    {
        return new S3BlobStore(/* ... */);
    }
}
```

## Rules for a new port

1. At least two implementations exist or are planned — otherwise it is not a
   port, it is a class.
2. The interface speaks in domain terms (`inboxesFor`, not `queryFollowers`).
3. No host type appears in a signature: no `IUser`, no `Request`, no `PDO`.
4. Throw a port-specific exception, not a driver exception.
5. Add the contract test first; both hosts will implement against it.
