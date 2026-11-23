# Nextcloud host integration

This is the concrete mapping for [`AlohaSocial/social`](https://github.com/AlohaSocial/social),
the Nextcloud app. Read [using the core from a host](host-integration.md) first;
this page only adds the Nextcloud-specific decisions.

The app already implements posts, federation and a large UI. The core is not a
drop-in replacement for all of it. Treat the integration as a migration of one
slice at a time, behind a feature flag, while the old path keeps serving
everything that the core does not cover yet. The data and identity procedure
is in [migrating a Nextcloud installation](migration-from-nextcloud.md); do
not use the rollout below as a substitute for that procedure.

## 1. Choose the URL shape before writing code

The core builds actor, post, inbox and collection URLs from
`social/instance.url`. For a Nextcloud app there are two valid shapes:

**A. Prefix (no webserver rewrite).** Serve the core under a path the app
already owns, for example:

```
https://cloud.example/apps/social/federation/@alice
https://cloud.example/apps/social/federation/inbox
https://cloud.example/apps/social/federation/api/v1/posts
```

Set `social/instance.url` to `https://cloud.example/apps/social/federation`,
strip that prefix from the request path before calling the core kernel, and
make the app's WebFinger handler return the prefixed actor URL as `self`.
ActivityPub permits paths in actor ids.

**B. Root paths through a rewrite.** A webserver rule maps `/api/v1`, `/inbox`,
`/inbox/shared`, `/@{handle}` and `/.well-known/webfinger` to the app's core controller. Set
`social/instance.url` to the root. This is the shape the current app uses for
its Mastodon-compatible API, so the rewrite mechanism may already exist.

Either shape works. Do not mix them: an actor URL that points at a path the
controller does not serve is worse than a slightly longer URL.

## 2. Add the dependency

The app's `composer.json` uses `nextcloud/social` and has a committed
`vendor/` autoloader. Add the VCS repository and the package, then update:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/SchBenedikt/core"
    }
  ],
  "require": {
    "alohasocial/core": "^0.7"
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

```bash
composer update alohasocial/core
composer dump-autoload -o
```

The app already does `require_once __DIR__ . '/../../vendor/autoload.php'` in
`lib/AppInfo/Application.php`; no second autoloader is required. `^0.7` means
the 0.7.x line, matching the supported UI and HostBridge contract in the
[compatibility matrix](host-integration.md#compatibility). While the package is
not on Packagist, the VCS repository must be able to resolve the release tag;
do not float `dev-main` for a release. The Nextcloud host repository itself is
not versioned here; the adapter work has not started.

## 3. Implement the ports with OCP

| Core port | Nextcloud source | Note |
| --- | --- | --- |
| `Config` | `OCP\IConfig` | App id `social`; values are strings |
| `Db\Connection` | `OCP\IDBConnection` | Doctrine DBAL; see the adapter below |
| `Db\TransactionRunner` | the same adapter | Required for atomic post writes; `NextcloudConnection` implements it with DBAL `transactional()` |
| `Http\HttpClient` | `OCP\Http\Client\IClientService` | Keep Nextcloud's CA/proxy policy; or use `CurlHttpClient` |
| `Http\BoundedHttpClient` | `IClientService` with a streamed body cap and address pinning | Needed for remote media; a plain `HttpClient` wrapper leaves the remote importer disabled |
| `Network\HostResolver` | `OCP\IDNSResolver` or the app's resolver | The remote-media guard needs every A/AAAA answer before a request |
| `Storage\BlobStore` | `OCP\Files\IAppData` | Pass it to `forHost()` to enable media; the core supplies the local filesystem adapter for the standalone host |
| `LocalActorDirectory` | `OCP\IUserManager` / external user backend | The app already has external users; include them |
| `Jobs\JobQueue` | core `PdoJobQueue` over `NextcloudConnection` | Nothing to implement; the core owns atomic reserve, retry and dead-letter rules. A custom queue adapter must also add `deadLetter()` for `0.7.0` |
| `ActivityLog` | core `PdoActivityLog` over `NextcloudConnection` | Nothing to implement; the primary key is the replay guard |
| `ActorResolver` | `HttpActorResolver` or the app's actor cache | Resolve key id → actor, never trust a supplied key |
| `RsaKeyPair` | sealed key in app config | Open with `OpenSslSecretBox`, then `RsaKeyPair::fromPems()` |

### Config adapter

```php
final class NextcloudConfig implements Config
{
    public function __construct(private IConfig $config) {}

    public function get(string $app, string $key, ?string $default = null): ?string
    {
        $value = $this->config->getAppValue($app, $key, $default ?? '');
        return $value === '' ? $default : $value;
    }

    public function set(string $app, string $key, string $value): void
    {
        $this->config->setAppValue($app, $key, $value);
    }

    public function delete(string $app, string $key): void
    {
        $this->config->deleteAppValue($app, $key);
    }

    // getBool(), getInt(), has() and all() are small helpers over the same
    // calls. Keep the values as strings, exactly like the port promises.
}
```

Store `instance.url` without a trailing slash. It must include the prefix from
step 1 when you use shape A.

### Database adapter

`IDBConnection` is Doctrine DBAL, not PDO. Wrap it; do not change the core's
repositories to speak Doctrine. The port is deliberately small:

```php
final class NextcloudConnection implements Connection, TransactionRunner
{
    public function __construct(private IDBConnection $db) {}

    public function execute(string $sql, array $parameters = []): int
    {
        return (int) $this->db->executeStatement($sql, $parameters);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        return $this->db->executeQuery($sql, $parameters)->fetchAllAssociative();
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $row = $this->db->executeQuery($sql, $parameters)->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $value = $this->db->executeQuery($sql, $parameters)->fetchOne();
        return $value === false ? null : $value;
    }

    public function transaction(callable $work): mixed
    {
        // TransactionRunner has the same contract as Connection::transaction().
        // Nested calls join the DBAL transaction and an exception rolls the
        // whole chain back.
        return $this->db->transactional(fn (): mixed => $work($this));
    }

    public function driver(): string
    {
        // Adjust to the DBAL version Nextcloud ships; only a few SQL
        // statements use this value.
        return $this->db->getDatabasePlatform()->getName();
    }
}
```

Normalize booleans and `DateTimeImmutable` parameters exactly as
`PdoConnection` does if you pass them; the core's repositories currently pass
strings and null, but the port contract allows the wider set. The adapter also
implements `TransactionRunner`; this is what lets `PostService` roll back the
post, its side rows and its delivery jobs together. `0.7.0` adds no Nextcloud
table or column. Test the adapter
against `PostRepositoryTestCase`, `FollowerRepositoryTestCase` and
`FollowingRepositoryTestCase` with the app's database, not only with SQLite.

### HTTP client adapter

The core treats a non-2xx answer as data and a transport failure as an
exception. Preserve that distinction:

```php
final class NextcloudHttpClient implements HttpClient
{
    public function __construct(private IClientService $clients) {}

    public function request(string $method, string $url, array $headers = [], string $body = '', int $timeoutSeconds = 10): HttpResponse
    {
        try {
            $response = $this->clients->newClient()->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeoutSeconds,
                'http_errors' => false,
            ]);
        } catch (ClientException $exception) {
            throw new HttpClientException($exception->getMessage(), 0, $exception);
        }

        return new HttpResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string) $response->getBody(),
        );
    }
}
```

Check the exact option names against the Nextcloud/Guzzle version you build
against. The contract is what matters: no exception for a 404/503, TLS
verification on, and a stable exception type for DNS/TLS/timeout failures.

For remote media, add a second adapter implementing `BoundedHttpClient`: read
the response incrementally up to `maxBytes`, and honour the supplied
`resolvedIps` when opening the connection. If the Nextcloud client cannot pin
an address, use the core's `CurlHttpClient` for this one path and keep the
host wrapper for delivery; alternatively leave remote media disabled. Do not
route remote media through an unbounded wrapper just to make the feature
appear enabled.

### Users and keys

```php
final class NextcloudActorDirectory implements LocalActorDirectory
{
    public function __construct(private IUserManager $users) {}

    public function has(string $userId): bool
    {
        return $this->users->userExists($userId);
    }
}
```

The app's external users are still users in the host's sense; include them if
they can own an Aloha account. The core does not have a user table. Its
identity boundary is [ADR 0006](../adr/0006-host-owned-accounts.md): Nextcloud
owns the account, session, group and administrator checks; the core receives
only the authenticated `userId` and the token scopes. Do not add groups or
admin flags to `LocalActorDirectory`; keep those checks in the app.

The private key belongs in the host's secret storage. If it is sealed in app
config:

```php
$box = OpenSslSecretBox::fromBase64Key($sealedKey);
$keys = RsaKeyPair::fromPems($box->open($sealedPrivatePem), $publicPem);
```

Do not put the PEM into a log line, an exception message or a response. The
core only needs the `RsaKeyPair` object.

## 4. Queue and activity log

These are the two ports that make the difference between a demo and a server.
The core supplies both adapters; the app does not implement the algorithms.

### Use the core adapters

```php
use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Support\RandomIdGenerator;

JobSchema::create($connection);
AccountDeletionSchema::create($connection);
ActivityLogSchema::create($connection);

// Existing app database instead: JobSchema::upgrade($connection);
// AccountDeletionSchema::upgrade($connection);
$queue = new PdoJobQueue($connection, new SystemClock(), new RandomIdGenerator());
$activityLog = new PdoActivityLog($connection);

$app = Application::forHost(
    config: $config,
    database: $connection,
    httpClient: $http,
    signingKey: $keys,
    localActors: $actors,
    queue: $queue,
    activityLog: $activityLog,
);
```

`$connection` is the `NextcloudConnection` adapter from section 3. Its
`transaction()` method must really join the DBAL transaction, because
`PdoJobQueue::reserve()` relies on the conditional update being atomic.
`JobSchema`, `AccountDeletionSchema` and `ActivityLogSchema` are the canonical
definitions; the app's migration should translate their statements into
Nextcloud's schema API rather than maintaining a second column list. For an
existing app database, the migration must add the `subject_type`/`subject_id`
columns and `social_job_subject` index and create `social_account_deletion`
with its state index. The core's idempotent `JobSchema::upgrade($connection)`
and `AccountDeletionSchema::upgrade($connection)` do exactly that through the
`Connection` adapter; the equivalent Nextcloud schema migration must be
idempotent as well and must not drop pending `social_job` rows.

### Queue table

`social_job` has exactly these columns:

| Column | Type | Null |
| --- | --- | --- |
| `id` | `VARCHAR(64)` | no, primary key |
| `type` | `VARCHAR(64)` | no |
| `payload` | `TEXT` | no |
| `available_at` | `VARCHAR(20)` | no |
| `attempts` | `INTEGER` | no |
| `reserved_at` | `VARCHAR(20)` | yes |
| `dead_at` | `VARCHAR(20)` | yes |
| `subject_type` | `VARCHAR(32)` | yes |
| `subject_id` | `VARCHAR(255)` | yes |

Indexes: `social_job_due (available_at, id)`, `social_job_dead (dead_at)`,
`social_job_subject (subject_type, subject_id)`.

The adapter reserves due rows with a conditional update inside a transaction,
so two workers cannot run the same job. It applies the same five-attempt
ceiling and 30 s to 3600 s backoff as `InMemoryJobQueue`; `complete()` removes
the row and `fail()` either reschedules it or sets `dead_at`. A reservation
older than `PdoJobQueue::RESERVATION_SECONDS` (300 s) is recoverable after a
worker crash. `pendingCount()` and `deadLetterCount()` feed the app's health
page. The same worker handles `JobType::CLEANUP`; the app should enqueue one
cleanup job per day (or hour, for a large instance) with its retention values,
as described in [retention cleanup](retention-cleanup.md). The job needs no
Nextcloud-specific table beyond the existing attachment, post-link,
notification and remote-thread schemas.

### Activity-log table

`social_activity_log` has exactly these columns:

| Column | Type | Null |
| --- | --- | --- |
| `activity_id` | `VARCHAR(512)` | no, primary key |
| `seen_at` | `VARCHAR(20)` | no |

Index: `social_activity_log_seen (seen_at)`.

`claim()` inserts the id and returns false on a duplicate; `release()` deletes
it when handling failed. Rows older than `PdoActivityLog::RETENTION_SECONDS`
(86400 s) are pruned on the next claim, so no separate cleanup job is needed.
The primary key is the actual guard — a select-then-insert would be a race.

## 5. Serve the routes

Register one thin controller that maps the host request to the core request
and the core response back. Do not write a controller per core route.

```php
final class CoreApiController extends Controller
{
    public function __construct(
        private Application $core,
        private IUserSession $session,
        string $appName,
    ) {
        parent::__construct($appName);
    }

    #[NoCSRFRequired]
    #[PublicPage]
    public function dispatch(string $path): Response
    {
        $request = new CoreRequest(
            method: $this->request->getMethod(),
            path: '/' . ltrim($path, '/'),
            query: $this->request->getParams(),
            headers: $this->request->getHeaders(),
            body: (string) file_get_contents('php://input'),
            userId: $this->session->getUser()?->getUID(),
        );

        $coreResponse = $this->core->kernel->handle($request);

        $response = new Response();
        $response->setStatus($coreResponse->status);
        foreach ($coreResponse->headers as $name => $value) {
            $response->addHeader($name, $value);
        }
        $response->setContent($coreResponse->body);

        return $response;
    }
}
```

The route grep in `appinfo/routes.php` needs a catch-all below the chosen
prefix, for all methods the manifest uses:

```php
['name' => 'core_api#dispatch', 'url' => '/federation/{path}',
 'requirements' => ['path' => '.+'], 'verb' => 'GET|POST|PUT|DELETE'],
```

Map `GET|POST|...` to the manifest's methods. Keep `#[NoCSRFRequired]` on API
routes: API clients authenticate with a token/session, not a Nextcloud form
token. `#[PublicPage]` lets the controller build a request with `userId: null`;
the core's `RequireAuthentication` still answers 401 for a protected route.
Do not let Nextcloud redirect a JSON request to the login page instead.

Authentication is still the host's job: the session or bearer token produces
`userId`. The core only decides whether the route requires one.

Rate limits are declared by the core manifest. Reuse the app's existing rate
limit middleware with the route's `rateLimit` value; do not add a second
limiter in the controller.

## 6. Migrations and existing tables

Create the app's tables through Nextcloud migrations, with the same columns
and indexes as the core's schema classes. The SQL strings in `PostSchema`,
`AttachmentSchema`, `FollowerSchema`, `FollowingSchema`, `RemotePostSchema`,
`RemoteThreadSchema`,
`BlockSchema`, `InboundBlockSchema`, `AtprotoPublicationSchema`, `AtprotoInboundSchema`, `JobSchema`
and `ActivityLogSchema` are the canonical
definition; translate them into the app's schema API rather than copying a
second column list. `RemotePostSchema` now creates both
`social_remote_post` and the ordered `social_remote_post_attachment` link
table; `AttachmentSchema` has the nullable `source_url` and its unique index.
The two tables in section 4 are the queue and replay-log tables; do not
hand-write a different shape.

If the app already has a `social_following` table from the earlier core
version, add:

```
inbox_url VARCHAR(512) NOT NULL
```

before enabling `POST /api/v1/following`; the repository writes that column.
If `social_follower` already exists, add the nullable
`shared_inbox_url VARCHAR(512) NULL` column before enabling follower reads or
writes. Existing rows need no backfill and keep using their individual inbox;
the host serves `POST /inbox/shared` with the same controller as `/inbox`.
For the Mastodon-compatible local follow/unfollow and relationship routes,
add `is_local SMALLINT NOT NULL DEFAULT 0` to `social_follower`; existing rows
stay remote and only `POST {prefix}/accounts/{id}/follow` writes `1`. A host
without the column cannot read followers or serve those routes. For the
notification routes, create `social_notification` from
`NotificationSchema::statements()`; there is no legacy import. Repeating an
interaction cannot duplicate a notification, and deleting a post/follow leaves
the historical row for the core cleanup job to remove later if it was read and
older than the configured notification retention. A host that already has the
listing-only table adds the nullable `read_at` marker and unread index:

```sql
ALTER TABLE social_notification ADD COLUMN read_at VARCHAR(20) NULL;
CREATE INDEX social_notification_unread
  ON social_notification (recipient_id, read_at, created_at, id);
```

Old rows stay unread, and dismiss/clear only update `read_at`; the host can
prune read rows on its own schedule.
Create `social_remote_post` before enabling inbound `Create`/`Delete`/`Announce`
or reading the combined home timeline; it has no legacy rows to import. Create
`social_block` before enabling `GET`/`POST /api/v1/blocks` or the combined home
timeline; its query reads the table on every page.
Create `social_inbound_block` before enabling the signed inbox's Block/Undo
handling; it stores the Block URI needed to verify nested and URI-only Undo.

## 7. Test the integration in the app

Add a PHPUnit test that boots `Application::forHost()` with the app's database
(an in-memory SQLite database is enough for the composition test) and a fake
HTTP client. Assert:

1. the app's `Kernel` serves a post write and a public timeline read;
2. a `POST /api/v1/following` reaches the fake peer inbox;
3. `GET /@{handle}/following` contains the actor;
4. the app's registered routes match `Routes::manifest()` by name, method and
   path;
5. a request without a user gets the core's 401 shape, not a redirect.

Then run the core's contract tests against the Nextcloud adapters. A smoke test
through the app is not enough: the adapter has to satisfy the same case as the
in-memory implementation.

## 8. Rollout

1. **Preparation:** add the package, adapters and migrations; boot the core in
   a test only. No production route yet.
2. **Posts/timelines slice:** enable the core's post and timeline routes for a
   test account or a feature flag. Keep the old endpoints for everyone else.
3. **Actor surface:** enable the actor document, WebFinger, followers,
   following and outbox for that account. Verify with a real peer that the
   actor URL, key id and WebFinger `self` agree.
4. **Outbound follow:** enable `POST`/`DELETE /api/v1/following`; check that
   the peer receives the signed `Follow` and `Undo`.
5. **Inbound inbox:** only after a durable activity log and actor resolver are
   in place. Serve both `/inbox` and `/inbox/shared`; the shared route uses the
   same controller and checks. Start with a test domain; a replay or a wrong
   key must be refused, not logged and ignored.
6. **UI:** point one page at the core routes through the
   [`HostBridge`](shared-ui.md), not a host global. Keep the old path until the
   page has no feature the core lacks.

Do not run the old and new stacks for the same handle. One actor URL must have
one owner, or a peer will see two different answers for the same account.

## What this buys the Nextcloud app

- The app keeps its users, permissions, navigation, Vue UI and deployment; it
  stops maintaining a second federation core. Existing actor and key URLs are
  preserved by the [migration procedure](migration-from-nextcloud.md).
- Signatures, actor documents, inbox verification, Follow/Undo,
  Create/Delete/Announce storage and delivery are shared with the standalone
  product and tested without Nextcloud.
- The core's `PdoJobQueue` and `PdoActivityLog` provide durable delivery and
  replay protection over the app's database; the app no longer has to build
  its own reserve/retry/prune implementation.
- The route manifest gives the app and the standalone one API contract, which
  is what makes a shared front end possible.
- A bug in validation or replay handling is fixed once, not twice.

## What is still host-specific

- Login, sessions, external users, CSRF, CORS, rate limiting, groups, roles and the admin UI. The core receives an authenticated id, not a Nextcloud user or administrator object; see [ADR 0006](../adr/0006-host-owned-accounts.md).
- The core now owns the optional Mastodon-compatible subset (instance
  metadata/statistics, public timeline, status read/create/delete with local
  poll creation/voting, local favourite/unfavourite, local follow/unfollow and
  relationships, private lists and list timelines, local notifications with
  dismiss/unread state, media upload/update/delete, account profiles and
  follower/following lists, account statuses and verify credentials). The app can
  delegate those routes instead of keeping a second implementation;
  OAuth/token issuance, remote timelines, favourite federation, remote boost federation,
  remote notifications, streaming and the remote account/admin surface stay
  host-specific. Local search, notification dismiss/unread state and local
  account profiles, follow lists and private lists are done in the core; no
  locally implementable profile follow-up is a host blocker. Remote-post backfill,
  thread/context fetching and local/remote media are in the core; the
  Nextcloud HTTP adapter must implement `BoundedHttpClient` (or use the
  supplied `CurlHttpClient`) before remote attachment ingestion or thread
  fetching can be enabled.
- Key storage and migrations are host adapters; the core supplies the
  interfaces and the schema definitions. The durable queue and activity log
  are core adapters and only need the app's `Connection`.
