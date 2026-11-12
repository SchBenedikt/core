# Using the core from a host

This repository is a library, not an application. A **host** is a PHP
application that provides the platform ports, authenticates users, serves HTTP
and runs the worker. The Nextcloud app and the future standalone server are the
two intended hosts.

This guide is the contract between the two sides: what the core gives you, what
you have to provide, and how to boot it without copying the service wiring.

## What you get, what you own

| The core gives you | The host owns |
| --- | --- |
| Posts, timelines, follows, actors, WebFinger, outbox and inbox behaviour | Login, sessions, user directory and authorization |
| The route manifest and one handler per route | Mapping its HTTP framework to `Core\Http\Request`/`Response` |
| Validation, JSON error shape and the `requires_auth` decision | Sessions, bearer tokens, CSRF, CORS and rate limiting |
| ActivityPub documents, signatures, replay guard, inbound Create/Delete/Announce and delivery | Key storage, TLS, proxy configuration and DNS |
| In-memory and PDO repositories, durable `PdoJobQueue`/`PdoActivityLog`, plus their contract tests | The migration runner |
| ATProto record/TID publishing through the `AtprotoAccountResolver` and `AtprotoPublisher` ports | Account linking, OAuth/app-password sessions, token refresh and PDS credentials |
| A reference assembly for tests and a production assembly for hosts | The worker process/cron and its supervision |

The important boundary: **the core never imports a host**. If you need
something the core cannot know, implement a port in your host rather than
putting host code into `src/`.

## Install it in the other repository

The package is not on Packagist yet. Until there is a tagged release, add the
current Git repository as a VCS repository and require `dev-main` explicitly.
This is the `composer.json` shape for a host such as the Nextcloud app:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/SchBenedikt/core"
    }
  ],
  "require": {
    "alohasocial/core": "dev-main"
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Then:

```bash
composer update alohasocial/core
composer dump-autoload -o
```

For a reproducible deployment, pin a commit (`dev-main#c05f1aa`) or, once tags
exist, a version range such as `^1.0`. The package requires PHP 8.3+ and the
extensions listed in the [README](../../README.md#requirements). The host's
own autoloader includes the package's PSR-4 mapping; no extra bootstrap file is
needed.

If the host already has a `vendor/autoload.php` (as the Nextcloud app does),
the core is available after that `require_once`. Do not copy `src/` into the
host — that would create a second copy with a different upgrade path.

## Boot the production assembly

`Application::forHost()` is the composition root for a real host. It uses the
SQL repositories and takes your ports:

```php
use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Jobs\PdoJobQueue;

$queue = new PdoJobQueue($connection, $clock, $ids);
$activityLog = new PdoActivityLog($connection);

$app = Application::forHost(
    config: $config,             // Core\Platform\Config\Config
    database: $connection,       // Core\Platform\Db\Connection
    httpClient: $http,           // Core\Platform\Http\HttpClient; BoundedHttpClient enables remote media
    signingKey: $keys,           // Core\Protocol\ActivityPub\RsaKeyPair
    localActors: $actors,        // Core\Domain\Federation\LocalActorDirectory
    queue: $queue,               // Core\Jobs\JobQueue, durable
    activityLog: $activityLog,   // Core\Domain\Federation\ActivityLog, durable
    actorResolver: $resolver,    // optional; without it both inbox routes are 503
    blockedActors: $policy,      // optional; instance-wide block policy
    blobStore: $blobs,           // optional; without it media routes are 503
    imageProcessor: $images,     // optional; default is GdImageProcessor
    hostResolver: $dns,          // optional; default is DnsHostResolver
    atprotoAccounts: $atprotoAccounts, // optional; enables AT Protocol publishing
    atprotoPublisher: null,      // optional; default is XrpcAtprotoPublisher($http)
    atprotoPublications: null,   // optional; default is PdoAtprotoPublicationRepository($database)
    logger: $logger,             // optional PSR-3 logger
    debug: false,                // never true in production
);
```

The object exposes the parts a host needs:

| Member | Use |
| --- | --- |
| `$app->kernel` | `handle(Core\Http\Request): Core\Http\Response` |
| `$app->routes` | The manifest to serve and to test against |
| `$app->worker` | `runOnce()` from cron or a worker loop |
| `$app->queue` | The same queue the services write to |
| `$app->posts`, `$app->timelines`, `$app->followers`, `$app->following` | Services for host pages or commands; the home timeline includes remote posts from followed actors |
| `$app->inbox` | `null` when inbound federation is not configured |
| `$app->backfill` | Bounded outbox catch-up for a followed actor; `null` without an HTTP client |
| `$app->media` | Upload/presentation service; `null` when no `BlobStore` was supplied. Remote imports use the same validation and storage when the HTTP client is bounded |
| `$app->atproto` | AT Protocol publishing service; present even when disabled, but the route reports `503` until an account resolver and publisher are supplied |

`queue` and `activityLog` are required on purpose. An in-memory queue loses
deliveries on restart, and an in-memory replay guard accepts the same activity
again after a restart. Tests may pass `InMemoryJobQueue` and
`InMemoryActivityLog`; production should pass the core's `PdoJobQueue` and
`PdoActivityLog` over the host's `Connection`.

`Application::inMemory()` remains the demo/test assembly. Do not use it as a
production bootstrap.

## The ports you have to provide

| Port | What the host must do | Core implementation |
| --- | --- | --- |
| `Clock` | Return the current UTC time | `SystemClock` |
| `Config` | Store `social/instance.url`, `social/instance.name` and the host's settings | `ArrayConfig` only for tests |
| `Db\Connection` | Execute parameterised SQL, fetch rows, join transactions and report the driver | `PdoConnection` if PDO is available |
| `Http\HttpClient` | Return non-2xx answers as `HttpResponse`; throw `HttpClientException` only on transport failure | `CurlHttpClient` |
| `Http\BoundedHttpClient` | Also cap the response body while reading and pin a validated address; required for remote media | `CurlHttpClient`; test `FakeHttpClient` / `LocalHttpClient` |
| `Network\HostResolver` | Resolve every A/AAAA answer so the remote-media guard can reject private addresses | `DnsHostResolver`; hosts may supply their DNS policy |
| `Storage\BlobStore` | Store media originals and thumbnails; pass it to `forHost()` to enable the media routes | `LocalBlobStore`, `InMemoryBlobStore` |
| `LocalActorDirectory` | Answer whether a user id is an account on this instance | host-specific |
| `Jobs\JobQueue` | Reserve, complete, retry and dead-letter jobs durably | `PdoJobQueue`; `InMemoryJobQueue` for tests |
| `ActivityLog` | Claim an activity id exactly once, release it on failure, expire old ids | `PdoActivityLog`; `InMemoryActivityLog` for tests |
| `ActorResolver` | Resolve a signing key id to a remote actor | `HttpActorResolver`, optionally wrapped in `CachingActorResolver` |
| `BlockedActorPolicy` | Optional instance-wide decision whether inbound content from an actor is refused | `NoBlockedActors` by default; host policy otherwise |
| `FollowPolicy` | Optional per-account decision whether an inbound Follow creates an edge | `AcceptAllFollows` by default; host policy otherwise |
| `InboxResolver` | Return the inboxes for a local author | `FollowerInboxResolver` by default |
| `RsaKeyPair` | Load the instance key from the host's secret store | `fromPems()` / `SecretBox` |
| `ImageProcessor` | Produce a bounded thumbnail; the default GD adapter is complete for local use | `GdImageProcessor` |
| `AtprotoAccountResolver` | Resolve a local user to a linked DID, handle, PDS URL and access token; refresh the token before returning it | none; host-specific |
| `AtprotoPublisher` | Write an `app.bsky.feed.post` record to a PDS; the default authenticated XRPC adapter is sufficient unless the host has its own client | `XrpcAtprotoPublisher`; fake in tests |

### Config keys

The required keys are:

```
social/instance.url    absolute base URL, no trailing slash required
social/instance.name   display name
```

The media keys are optional and documented in
[media](media.md#configuration). In particular, remote attachment ingestion
is controlled by `social/media.remote_fetch` (default on), with
`media.remote_max_bytes`, `media.remote_timeout_seconds` and
`media.remote_max_redirects` as limits.

`instance.url` is what every actor, post and activity id is built from. It must
be the public URL under which the core's routes are actually served. If that
URL includes a path prefix, the same prefix must be in the actor URL and the
host must strip it before passing the request to the kernel.

### Database

The core owns the schema classes, not the migration mechanism:

- `PostSchema` → `social_post` and `social_post_attachment`
- `AttachmentSchema` → `social_attachment` (metadata, including nullable `source_url` with its unique index; bytes are in `BlobStore`)
- `FollowerSchema` → `social_follower` (includes the individual and nullable shared inbox)
- `FollowingSchema` → `social_following` (includes `inbox_url`)
- `RemotePostSchema` → `social_remote_post` (remote posts and boosts) and `social_remote_post_attachment` (ordered remote-media links)
- `RemoteThreadSchema` → `social_remote_thread_post` (on-demand thread ancestors and direct replies, keyed by object id)
- `BlockSchema` → `social_block` (per-user blocks)
- `JobSchema` → `social_job`
- `ActivityLogSchema` → `social_activity_log`
- `AtprotoPublicationSchema` → `social_atproto_publication` (AT Protocol idempotency rows)

A host with PDO can wrap its handle in `PdoConnection` and call the schema
`create()` methods for a fresh installation. A host without PDO implements
`Connection` over its own database API. The SQL is deliberately plain and the
port is small; see [database](database.md).

If `social_following` already exists from an earlier core version, add the
`inbox_url VARCHAR(512) NOT NULL` column through the host's migration path.
If `social_follower` exists from an earlier core version, add the nullable
`shared_inbox_url VARCHAR(512) NULL` column. Existing rows need no backfill and
fall back to their individual inbox; see
[shared inboxes](shared-inboxes.md#migration).
If `social_attachment` predates remote media, add nullable `source_url
VARCHAR(512)` and its unique index, and create
`social_remote_post_attachment` from `RemotePostSchema::statements()`.
For thread/context fetching, create `social_remote_thread_post` from
`RemoteThreadSchema::statements()` and wire a `RemoteThreadRepository` into
`Application::forHost()`; a host that does not create the table must not expose
the route. For AT Protocol publishing, create `social_atproto_publication` from
`AtprotoPublicationSchema::statements()` and supply an `AtprotoAccountResolver`;
supplying no resolver leaves the existing route at `503 atproto_not_configured`
without writing anything. There is no migration runner and no automatic retention job in this
repository. A host that wants context to expire calls
`RemoteThreadRepository::deleteFetchedBefore()` on its own schedule.

### Durable queue and activity log

The core now supplies the two adapters a production host needs, both over the
same `Connection`:

```php
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\PdoJobQueue;

JobSchema::create($connection);
ActivityLogSchema::create($connection);

$queue = new PdoJobQueue($connection, $clock, $ids);
$activityLog = new PdoActivityLog($connection);
```

`JobSchema::statements()` and `ActivityLogSchema::statements()` are the
canonical DDL. The tables below are exactly those columns; the indexes belong
to the same schema classes.

`social_job`:

| Column | Type | Null |
| --- | --- | --- |
| `id` | `VARCHAR(64)` | no, primary key |
| `type` | `VARCHAR(64)` | no |
| `payload` | `TEXT` | no |
| `available_at` | `VARCHAR(20)` | no |
| `attempts` | `INTEGER` | no |
| `reserved_at` | `VARCHAR(20)` | yes |
| `dead_at` | `VARCHAR(20)` | yes |

Indexes: `social_job_due (available_at, id)`, `social_job_dead (dead_at)`.

`social_activity_log`:

| Column | Type | Null |
| --- | --- | --- |
| `activity_id` | `VARCHAR(512)` | no, primary key |
| `seen_at` | `VARCHAR(20)` | no |

Index: `social_activity_log_seen (seen_at)`.

`PdoJobQueue` reserves with a conditional update inside a transaction (two
workers cannot both claim a row), retries five times with the same 30 s to
3600 s backoff as `InMemoryJobQueue`, and dead-letters on the fifth failure. A
reservation older than `PdoJobQueue::RESERVATION_SECONDS` (300 s) is treated
as abandoned by a crashed worker and may be reserved again. `PdoActivityLog`
uses the `activity_id` primary key as the replay guard; a claim older than
`PdoActivityLog::RETENTION_SECONDS` (86400 s) is pruned on the next claim.

The host still applies the DDL through its migration mechanism and runs the
worker. It does not implement the queue's SQL, retry rules or replay handling.

### HTTP client and signatures

`HttpClient` is not allowed to throw on a 404 or 503: the delivery code decides
whether a status is retryable. Throw `HttpClientException` only when no
response was produced (DNS, TLS, connection, timeout). Keep TLS verification
on. `CurlHttpClient` is a complete implementation if the host does not already
have a suitable client.

A host that already has an HTTP client should wrap it; that keeps the host's
CA bundle, proxy and SSRF policy in force instead of adding a second stack. For
remote media, the wrapper must also implement `BoundedHttpClient`: enforce the
body cap while reading and pin the caller-supplied validated addresses. Do not
substitute an unbounded wrapper and then disable the guard; `Application`
checks the interface and leaves remote media disabled when it is absent. A
host can supply `hostResolver` to use its own DNS policy as well.

## Serve HTTP

The core expects a normalized `Core\Http\Request`. The host builds it from its
own request object:

```php
use AlohaSocial\Core\Http\Request;

$request = new Request(
    method: $hostRequest->getMethod(),
    path: '/api/v1/posts',            // the path the manifest declares
    query: $hostRequest->getQueryParams(),
    headers: $hostRequest->getHeaders(),
    body: $rawBody,
    userId: $hostUser?->getUID(),      // null for a public request
);

$response = $app->kernel->handle($request);
```

Then map the core response to the host response:

```php
$hostResponse->setStatus($response->status);
foreach ($response->headers as $name => $value) {
    $hostResponse->addHeader($name, $value);
}
$hostResponse->setContent($response->body);
```

Do not decode and re-encode the body: ActivityPub responses have a precise
content type and media type, and JSON re-encoding can change both.

### Authentication and rate limits

The kernel's `RequireAuthentication` middleware only checks that `userId` is
present for a route with `requiresAuth: true`. The host still owns the session
or bearer-token check that produces `userId`. A missing user becomes a core
`401 authentication_required` response.

Rate limits are declared in `src/Contract/Routes.php` (for example `30/900`
for a post write). The host enforces its own limiter with that budget; the core
does not keep request counters.

### Path prefixes and protocol URLs

ActivityPub does not require actor URLs at the domain root. A path prefix is
legal:

```
https://cloud.example/apps/social/federation/@alice
https://cloud.example/apps/social/federation/inbox
```

If you use that shape:

1. set `social/instance.url` to the prefix;
2. strip the prefix before passing the path to the kernel;
3. make WebFinger return that prefixed actor URL as `self`;
4. serve the same prefix for every actor, inbox and collection route.

Alternatively, mount the core at the root through a rewrite. Either is valid;
what is not valid is an id that points at a URL the host does not actually
serve. Decide once and keep WebFinger, the actor document and delivery
consistent.

### Route parity

`Routes::manifest()` is the single source of truth. A host should have a test
that compares its registered routes with the manifest:

```php
$expected = \AlohaSocial\Core\Contract\Routes::manifest()->routes();
$actual = $hostRouter->registeredRoutes(); // name => [method, path]

foreach ($expected as $route) {
    self::assertArrayHasKey($route->name, $actual);
    self::assertSame($route->method, $actual[$route->name]['method']);
    self::assertSame($route->path, $actual[$route->name]['path']);
}
```

`php bin/export-routes` produces the same data as JSON for a CI diff. A host
that forgets a route should fail its build, not discover the gap in production.

## Run the worker

The queue is a port; the worker loop is core code:

```php
$handled = $app->worker->runOnce(10);
```

Call it from cron every minute, or from a supervised loop that sleeps when
`$handled` is zero. A non-zero batch should be followed immediately by another
`runOnce()` so a backlog drains without waiting for the next tick. The worker
logs a failed job, gives it back to the queue with backoff and continues with
the rest of the batch.

## Test the integration

The core's contract tests are the definition of a port. A new host adapter is
done when it passes the existing case, not when its own smoke test passes:

- `tests/Platform/Storage/BlobStoreTestCase.php`
- `tests/Domain/PostRepositoryTestCase.php`
- `tests/Domain/AttachmentRepositoryTestCase.php`
- `tests/Domain/FollowerRepositoryTestCase.php`
- `tests/Domain/FollowingRepositoryTestCase.php`
- `tests/Domain/RemotePostRepositoryTestCase.php`
- `tests/Domain/HomeTimelineRepositoryTestCase.php`
- `tests/Jobs/JobQueueTestCase.php`
- `tests/Domain/ActivityLogTestCase.php`

`tests/ApplicationHostTest.php` boots `Application::forHost()` with SQLite, the
PDO queue and activity log, and a fake inbox. Copy that shape into the host's
test suite: it proves that the host's ports, the SQL repositories and the HTTP
contract work together. Keep the core's `composer check` green and run the
host's own tests as well.

## What this buys you

- **One federation implementation.** Signatures, actor documents, inbox
  verification, Follow/Undo and delivery are written and tested once.
- **One security boundary.** A fix to signature or replay handling reaches
  both products; a host cannot accidentally weaken it with a second copy.
- **One API contract.** Both hosts serve the same route manifest and the same
  error shape, which is what lets one front end talk to both.
- **A testable core.** The suite runs without a server or network; the demo
  exercises real HTTP requests through the kernel.
- **A production-ready queue and replay guard.** `PdoJobQueue` and
  `PdoActivityLog` provide the durable behaviour a host needs over the same
  database port, instead of every host writing its own reserve/retry/prune
  logic.
- **A standalone path.** The same objects work with PDO, curl and a plain PHP
  process, so the standalone server is an assembly, not a rewrite.
- **Smaller hosts.** A host keeps its UI, accounts, permissions and deployment
  logic; it does not reimplement posts, follows or ActivityPub.

## What it does not give you yet

The core is a foundation, not a drop-in replacement for the current Nextcloud
app. It contains local image upload, validation and thumbnails, and remote
image ingestion for incoming `Create`/`Announce` posts with SSRF, size,
timeout and redirect guards. It does not contain an admin UI, the
Mastodon-compatible API or an object-storage adapter. It does provide a
bounded outbox backfill for missed `Create`/`Announce` activities. A host that
needs the remaining features either keeps its existing implementation for them
or waits for the corresponding core milestone. See [media](media.md) for the
storage, remote-fetch and cleanup contract; an object-storage adapter is still
a host decision.

The integration is therefore incremental: move one slice (for example posts
and timelines), keep the old path for everything else, and never run two
owners for the same handle.

## Adoption order

1. Add the Composer dependency and implement the ports; keep the host's UI.
2. Create the core tables through the host's migration system (including
   `social_remote_post`, `social_remote_post_attachment`, `social_block`,
   `social_attachment` and `social_post_attachment`).
3. Boot `Application::forHost()` in a test with SQLite, `PdoJobQueue`,
   `PdoActivityLog` and a fake HTTP client.
4. Serve one slice behind a feature flag (posts/timelines is the smallest).
5. Add actor documents, WebFinger and the follow collection.
6. Add `POST /inbox` and `POST /inbox/shared` only when the host has a durable
   activity log and actor resolver; without one, both are honestly 503.
7. Move the UI calls to the core routes once the slice is complete.

For the concrete Nextcloud mapping, see
[Nextcloud host integration](nextcloud-host.md). If this is an existing
installation rather than a fresh one, follow
[migrating a Nextcloud installation](migration-from-nextcloud.md) first: the
actor, key, post and follow identities must be checked before cutover. Shared
screens use the [`HostBridge`](shared-ui.md), never a host global.
