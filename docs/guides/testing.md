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
| `tests/Domain` | post, poll, favourite, attachment, follower, followed-actor, remote-post, remote-thread and block entities, local/remote/thread/block/timeline/attachment/favourite repository contracts (including poll round-tripping, local follower edges, their delivery exclusion, attachment-link and alt-text update semantics) and the activity-log contract |
| `tests/Service` | post, timeline and follower rules |
| `tests/Protocol` | ActivityStreams documents, RSA keys, signatures |
| `tests/Security` | the secret box |
| `tests/Jobs` | queue contract, PDO persistence/retry, dead letters and the worker |
| `tests/Http` | request, response, router, kernel |
| `tests/Contract` | the route manifest |
| `tests/ApplicationTest.php` | end to end through the in-memory assembly, including one batched signed job for two followers sharing an inbox and the individual-inbox fallback |
| `tests/Api/InboxTest.php` | signed follow/undo and refusal paths, including a signed Follow to `/inbox/shared`, the stored shared inbox and the remote-follow notification read back through the Mastodon route |
| `tests/Api/InboundTimelineTest.php` | signed inbox request → persistence → authenticated home timeline, including delete, boost, duplicate, unfollowed, policy-blocked and per-user-blocked actors |
| `tests/Api/BlockApiTest.php` | authenticated per-user block create, list, idempotence, ownership and removal |
| `tests/Api/MediaTest.php` | multipart upload into a temporary local store, original/thumbnail fetch, post and timeline presentation, and no-write rejection of wrong, corrupt and oversized files |
| `tests/Api/RemoteMediaTest.php` | signed inbound Create → guarded fetch from a local HTTP server → local original/thumbnail → home timeline, plus SSRF, redirect, oversized, corrupt, false type, 404, timeout, duplicate URL and attachment-limit cases; outbox backfill of a historical post, repair of an existing post after disabled media, idempotent repeats, SSRF/corrupt backfill entries and preservation of existing links when one candidate disappears |
| `tests/Api/RemoteThreadTest.php` | authenticated thread request → guarded target/parent/reply fetch from a local HTTP server → cache → JSON; ordered ancestors, direct replies, depth/reply limits, idempotent repeats, auth, missing/unfollowed/deleted/blocked targets, disabled fetching, invalid target, blocked ancestor, SSRF ancestor and oversized/slow targets |
| `tests/Api/AtprotoPublishTest.php` | post creation → queued AT Protocol job → fake XRPC `putRecord` → publication row → JSON; explicit route, repeat idempotency, unsupported/disabled/auth/missing-account and PDS-failure paths; post delete → `deleteRecord` → row removal, retry after failure and no-publication/no-duplicate cases |
| `tests/Domain/AtprotoOAuthSessionTest.php` | DID, token endpoint, client id, rotating tokens, mandatory `atproto` scope and the refresh leeway |
| `tests/Domain/InMemoryAtprotoSessionStoreTest.php` | the `AtprotoSessionStoreTestCase` contract: round trip, replacement, per-user isolation and empty lookup |
| `tests/Protocol/XrpcAtprotoSessionRefresherTest.php` | exact form-encoded token request, refresh-token rotation, DPoP nonce retry, terminal `invalid_grant`, retryable server/transport failures, missing nonce, wrong `sub`/scope and malformed responses |
| `tests/Protocol/Es256DpopProofProviderTest.php` | verifiable ES256 JWS, `dpop+jwt` header, public JWK, `htu` normalization, nonce claim, fresh `jti`, RSA-key and invalid-method rejection |
| `tests/Service/AtprotoSessionServiceTest.php` | a valid session is reused, an expiring session is refreshed and saved, an unknown user is null, a failed refresh keeps the old session and a changed DID is rejected |
| `tests/Api/MastodonApiTest.php` | optional compatibility routes through the real kernel: instance metadata, contact/registration fields, local stats and advertised poll/media/status limits, public timeline, media upload (success/description/media-in-status/auth/scope/disabled/invalid/corrupt/oversized/no-orphan/idempotency), media update (JSON/form/multipart, absent/empty description, ownership, attached refusal, binary refusal) and delete (empty object, blob cleanup, attached refusal, foreign/missing ids, scopes/disabled), create (form/JSON), poll creation (options, `expires_in`/`expires_at`, expiry flip, validation with no partial status, idempotent retry, projection on read/timeline), read, favourite/unfavourite (counts, viewer flags, repeat idempotency, absent no-op, auth, scope, deleted status), local follow/unfollow and relationship reads (flags, repeat idempotency, self/unknown targets, home-timeline visibility, scalar and `id[]`, deduplication, missing/empty input, 100-id limit, auth/scope/disabled), notifications (favourite/follow creation, repeat idempotency, survival after unfavourite, self suppression, type filters, pagination, remote actor projection, auth/scope/disabled, cursor ownership and a missing-table failure that leaves the primary favourite/follow intact while listing answers 503), notification dismiss/clear/unread_count (read-marker projection and hiding, repeated dismiss/clear idempotency, new events after clear, foreign/missing ids, count filters/cap, auth/scope/disabled and 503), local search (account/status matches, type filters, following filter, offset pages, private/deleted exclusion, invalid input, auth/scope/disabled and missing capabilities), account profiles and paged local follower/following lists (local/unknown/remote ids, local actor-URL mapping, remote target projection, cursor ownership, `max_id`/`since_id`/`min_id`, limits, `Link` and disabled feature), account statuses, verify, delete and cursor pagination |
| `tests/Service/MastodonCompatibilityTest.php` | prefix validation and non-overlap, feature default, parent/granular scope matching and route auth/scope/rate-limit metadata, including media upload/update/delete, favourites, local follow/relationship routes, account profile/follower/following routes, the four notification routes and search |
| `tests/Service/MastodonSearchTest.php` | a direct service call without `PostSearchRepository` answers `503 search_not_configured` for statuses, while `type=hashtags` needs no optional ports |
| `tests/Domain/InMemoryFavouriteRepositoryTest.php`, `tests/Domain/PdoFavouriteRepositoryTest.php` | the shared `FavouriteRepositoryTestCase`: pair uniqueness, per-user removal, batch public counts and viewer flags |
| `tests/Domain/InMemoryNotificationRepositoryTest.php`, `tests/Domain/PdoNotificationRepositoryTest.php` | the shared `NotificationRepositoryTestCase`: insert/find projection, duplicate-id refusal, recipient scoping, newest-first ordering with the id tie-break, exclusive cursors, type filters before the limit, scoped/idempotent read markers, hidden read rows, clear and filtered unread counts |
| `tests/Protocol/XrpcAtprotoPublisherTest.php` | exact `putRecord`/`deleteRecord` requests, bearer header, successful URI, 404-as-idempotent-delete, non-2xx status without leaking the body, transport failure and malformed success |
| `tests/Protocol/TidTest.php`, `tests/Protocol/AtprotoPostRecordTest.php` | deterministic valid TIDs, record shape, content-warning labels and AT Protocol text limits |
| `tests/Service/Federation/RemoteMediaFetcherTest.php` | guard reasons for private IPv4/IPv6, redirect revalidation, redirect budget, HTTP error, timeout, size limit and disabled switch |
| `tests/Platform/Http/CurlHttpClientTest.php` | production curl body cap and validated-address pinning against the local fixture server |
| `tests/Api/FollowPolicyTest.php` | a refused inbound Follow writes nothing and the same activity can be retried after the policy accepts it |
| `tests/Service/RemoteOutboxBackfillTest.php` | paging, deduplication, foreign/malformed entries, the item limit, blocked/unfollowed refusal before any fetch and cross-origin pagination; the application-level backfill→media path is covered by `RemoteMediaTest` |
| `tests/Service/InstanceInfoTest.php` | configurable instance metadata, language parsing, legacy email fallback, stale/missing capabilities and the optional local/SQL count projections |
| `tests/ApplicationHostTest.php` | `Application::forHost()` with SQL repositories, a signed inbox Create, the SQL home timeline, instance metadata plus SQL user/post/domain stats, an idempotent Mastodon status write, a SQL poll row/option round-trip, a SQL status search, a SQL favourite row, a SQL notification row with dismiss/read-marker persistence and an AT Protocol remote delete over SQL |

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
final class InMemoryFavouriteRepositoryTest extends FavouriteRepositoryTestCase { … }
final class PdoFavouriteRepositoryTest extends FavouriteRepositoryTestCase { … }
final class InMemoryNotificationRepositoryTest extends NotificationRepositoryTestCase { … }
final class PdoNotificationRepositoryTest extends NotificationRepositoryTestCase { … }
final class InMemoryHomeTimelineRepositoryTest extends HomeTimelineRepositoryTestCase { … }
final class PdoHomeTimelineRepositoryTest extends HomeTimelineRepositoryTestCase { … }
final class InMemoryAtprotoPublicationRepositoryTest extends AtprotoPublicationRepositoryTestCase { … }
final class PdoAtprotoPublicationRepositoryTest extends AtprotoPublicationRepositoryTestCase { … }
final class InMemoryAtprotoSessionStoreTest extends AtprotoSessionStoreTestCase { … }
```

If a behaviour is not in the case, the two implementations may drift. Add the
expectation to the case, not to one subclass. `PostRepositoryTestCase` also
exercises poll round-tripping, soft-delete retention, the optional
`PostSearchRepository` and `PostCount` implementations; the
`RemotePostRepositoryTestCase` covers distinct live-host counting through
`RemoteDomainCount`. `InMemoryActorSearchTest` covers the directory's
`LocalActorSearch` implementation directly, and `InstanceInfoTest` covers the
metadata and optional count projection. The PDO queue and activity-log
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
