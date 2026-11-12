# Testing

```sh
composer test
php vendor/bin/phpunit tests/Http
php vendor/bin/phpunit --filter HttpSignatureTest
```

`phpunit.xml` fails on warnings, risky tests and unexpected output, so a test
that prints or skips is a failure.

## Layout

| Directory | What lives there |
| --- | --- |
| `tests/Platform` | clock, config, PDO connection, blob stores and image processing |
| `tests/Domain` | post, attachment, follower, followed-actor, remote-post, remote-thread and block entities, local/remote/thread/block/timeline/attachment repository contracts and the activity-log contract |
| `tests/Service` | post, timeline and follower rules |
| `tests/Protocol` | ActivityStreams documents, RSA keys, signatures |
| `tests/Security` | the secret box |
| `tests/Jobs` | queue contract, PDO persistence/retry, dead letters and the worker |
| `tests/Http` | request, response, router, kernel |
| `tests/Contract` | the route manifest |
| `tests/ApplicationTest.php` | end to end through the in-memory assembly, including one batched signed job for two followers sharing an inbox and the individual-inbox fallback |
| `tests/Api/InboxTest.php` | signed follow/undo and refusal paths, including a signed Follow to `/inbox/shared` and the stored shared inbox |
| `tests/Api/InboundTimelineTest.php` | signed inbox request → persistence → authenticated home timeline, including delete, boost, duplicate, unfollowed, policy-blocked and per-user-blocked actors |
| `tests/Api/BlockApiTest.php` | authenticated per-user block create, list, idempotence, ownership and removal |
| `tests/Api/MediaTest.php` | multipart upload into a temporary local store, original/thumbnail fetch, post and timeline presentation, and no-write rejection of wrong, corrupt and oversized files |
| `tests/Api/RemoteMediaTest.php` | signed inbound Create → guarded fetch from a local HTTP server → local original/thumbnail → home timeline, plus SSRF, redirect, oversized, corrupt, false type, 404, timeout, duplicate URL and attachment-limit cases; outbox backfill of a historical post, repair of an existing post after disabled media, idempotent repeats, SSRF/corrupt backfill entries and preservation of existing links when one candidate disappears |
| `tests/Api/RemoteThreadTest.php` | authenticated thread request → guarded target/parent/reply fetch from a local HTTP server → cache → JSON; ordered ancestors, direct replies, depth/reply limits, idempotent repeats, auth, missing/unfollowed/deleted/blocked targets, disabled fetching, invalid target, blocked ancestor, SSRF ancestor and oversized/slow targets |
| `tests/Api/AtprotoPublishTest.php` | post creation → queued AT Protocol job → fake XRPC `putRecord` → publication row → JSON; explicit route, repeat idempotency, unsupported/disabled/auth/missing-account and PDS-failure paths; post delete → `deleteRecord` → row removal, retry after failure and no-publication/no-duplicate cases |
| `tests/Api/MastodonApiTest.php` | optional compatibility routes through the real kernel: instance, public timeline, create (form/JSON), read, account statuses, verify, delete, scopes, disabled-before-auth, custom prefix, cursor pagination and idempotency |
| `tests/Service/MastodonCompatibilityTest.php` | prefix validation and non-overlap, feature default, parent/granular scope matching and route auth/rate-limit metadata |
| `tests/Protocol/XrpcAtprotoPublisherTest.php` | exact `putRecord`/`deleteRecord` requests, bearer header, successful URI, 404-as-idempotent-delete, non-2xx status without leaking the body, transport failure and malformed success |
| `tests/Protocol/TidTest.php`, `tests/Protocol/AtprotoPostRecordTest.php` | deterministic valid TIDs, record shape, content-warning labels and AT Protocol text limits |
| `tests/Service/Federation/RemoteMediaFetcherTest.php` | guard reasons for private IPv4/IPv6, redirect revalidation, redirect budget, HTTP error, timeout, size limit and disabled switch |
| `tests/Platform/Http/CurlHttpClientTest.php` | production curl body cap and validated-address pinning against the local fixture server |
| `tests/Api/FollowPolicyTest.php` | a refused inbound Follow writes nothing and the same activity can be retried after the policy accepts it |
| `tests/Service/RemoteOutboxBackfillTest.php` | paging, deduplication, foreign/malformed entries, the item limit, blocked/unfollowed refusal before any fetch and cross-origin pagination; the application-level backfill→media path is covered by `RemoteMediaTest` |
| `tests/ApplicationHostTest.php` | `Application::forHost()` with SQL repositories, a signed inbox Create, the SQL home timeline, an idempotent Mastodon status write and an AT Protocol remote delete over SQL |

## Contract tests

An abstract test case is the definition of a port. Two implementations, one
case:

```php
final class InMemoryPostRepositoryTest extends PostRepositoryTestCase { … }
final class PdoPostRepositoryTest extends PostRepositoryTestCase { … }
final class InMemoryAttachmentRepositoryTest extends AttachmentRepositoryTestCase { … }
final class PdoAttachmentRepositoryTest extends AttachmentRepositoryTestCase { … }
final class PdoJobQueueTest extends JobQueueTestCase { … }
final class PdoActivityLogTest extends ActivityLogTestCase { … }
final class InMemoryRemotePostRepositoryTest extends RemotePostRepositoryTestCase { … }
final class PdoRemotePostRepositoryTest extends RemotePostRepositoryTestCase { … }
final class InMemoryRemoteThreadRepositoryTest extends RemoteThreadRepositoryTestCase { … }
final class PdoRemoteThreadRepositoryTest extends RemoteThreadRepositoryTestCase { … }
final class InMemoryBlockRepositoryTest extends BlockRepositoryTestCase { … }
final class PdoBlockRepositoryTest extends BlockRepositoryTestCase { … }
final class InMemoryHomeTimelineRepositoryTest extends HomeTimelineRepositoryTestCase { … }
final class PdoHomeTimelineRepositoryTest extends HomeTimelineRepositoryTestCase { … }
final class InMemoryAtprotoPublicationRepositoryTest extends AtprotoPublicationRepositoryTestCase { … }
final class PdoAtprotoPublicationRepositoryTest extends AtprotoPublicationRepositoryTestCase { … }
```

If a behaviour is not in the case, the two implementations may drift. Add the
expectation to the case, not to one subclass. The PDO queue and activity-log
tests also open a fresh SQLite connection to prove that a reservation, a retry
and a replay decision survive a restart; the in-memory versions cannot prove
that.

## Fakes and determinism

- `FrozenClock` — time moves only when the test says so; never `sleep()`.
- `RandomIdGenerator` — ids are random; assert shape, not value.
- `FakeHttpClient` — records requests and answers from a queue (default 202);
  also implements the bounded path.
- `LocalHttpClient` — maps the guarded test hostname to a real local HTTP
  fixture server for end-to-end tests; `RemoteMediaServer` starts that server
  and `remote-media-router.php` also serves the thread fixture objects.
- `FixedInboxResolver` — pretends the follow graph has entries.
- `RecordingJobHandler` / `FailingJobHandler` — worker tests without work.
- `SequenceIdGenerator` — deterministic ids when a test needs them.

Prefer a real object over a mock. Only ports with a remote side (`HttpClient`)
need a fake; everything else has an in-memory implementation.

## What to test

- Every new branch and every refusal: validation, 401/403/404, expired
  signature, changed body, replayed activity, actor mismatch, policy-blocked
  actor, per-user block filtering, inbound content from an unfollowed actor,
  disabled feature gates and insufficient token scopes.
- Every new port implementation against the existing contract case.
- A new endpoint end to end: request in, response out, side effect visible.

A bug fix starts with a failing test. If the test cannot fail, it is not a test.

## Running on several PHP versions

The suite runs on 8.3, 8.4 and 8.5; CI covers all three. Locally, a static PHP
build is enough:

```sh
"$HOME/.local/php-8.3.9/php" vendor/bin/phpunit
```

If OpenSSL cannot find a config in a static build, point it at the system one:

```sh
OPENSSL_CONF=/etc/ssl/openssl.cnf "$HOME/.local/php-8.3.9/php" vendor/bin/phpunit
```
