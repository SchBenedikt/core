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
| Posts, timelines, follows, actors, WebFinger, outbox and inbox behaviour | Login, sessions, user directory, roles, instance-admin rights and authorization |
| The route manifest and one handler per route | Mapping its HTTP framework to `Core\Http\Request`/`Response` |
| Validation, JSON error shape and the `requires_auth` decision | Sessions, bearer tokens, CSRF, CORS and rate limiting |
| ActivityPub documents, signatures, replay guard, inbound Create/Delete/Announce and delivery | Key storage, TLS, proxy configuration and DNS |
| In-memory and PDO repositories, durable `PdoJobQueue`/`PdoActivityLog`, plus their contract tests | The migration runner |
| ATProto record/TID publishing, remote delete, inbound relay/Jetstream/AppView import, OAuth session refresh and the authorization-code/PAR/revocation client through the `AtprotoAccountResolver`, `AtprotoPublisher`, `AtprotoDeleter`, `AtprotoEventSource` and `AtprotoSessionStore` ports | Account-linking UI/browser callback, the short-lived flow store, credential storage, PDS selection and the inbound event transport; the core provides metadata discovery, the OAuth protocol client and the replay-safe importer |
| A reference assembly for tests and a production assembly for hosts | The worker process/cron and its supervision |

The important boundary: **the core never imports a host**. If you need
something the core cannot know, implement a port in your host rather than
putting host code into `src/`.

The identity side of this table is settled in
[ADR 0006](../adr/0006-host-owned-accounts.md): the host authenticates the
caller, stores accounts/sessions/roles and enforces administrator rights. The
core receives only the resulting `Request::$userId` and, for the compatibility
routes, `Request::$scopes`. `LocalActorDirectory` is a read-only existence
check, not a user or login adapter.

## Install it in the other repository

The package is not on Packagist yet, and `@alohasocial/ui` is not on npm yet.
The verified local artifacts and the credential blocker are in
[release artifacts](../operations/release.md). Add the Git repository as a VCS
repository and require the supported line explicitly; the release tag is still
the Composer version. This is the `composer.json` shape for a host such as the
Nextcloud app:

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
  }
}
```

Then:

```bash
composer update alohasocial/core
composer dump-autoload -o
```

For a reproducible deployment, use the tagged `^0.7` line; if you are testing
an unreleased commit, pin it explicitly (`dev-main#c05f1aa`). For 0.x releases
the minor line is the compatibility boundary, so `^0.7` means 0.7.x; see
[Compatibility](#compatibility). The package requires PHP 8.3+ and the
extensions listed in the [README](../../README.md#requirements). The host's
own autoloader includes the package's PSR-4 mapping; no extra bootstrap file is
needed.

If the host already has a `vendor/autoload.php` (as the Nextcloud app does),
the core is available after that `require_once`. Do not copy `src/` into the
host — that would create a second copy with a different upgrade path.

## Compatibility

[`src/Version.php`](../../src/Version.php) is the core's version authority
(`Version::CURRENT`). `composer.json` deliberately has no `version` field; the
release tag supplies the Composer/Packagist version. [`ui/package.json`](../../ui/package.json)
is the UI package authority; the build exports the same value as `UI_VERSION`.
`tests/Contract/CompatibilityTest.php` compares the core constant, the UI
package and lockfile, and the TypeScript constants, so a release cannot
silently ship two lines.

For 0.x releases the minor line is the compatibility boundary; from 1.0 on it
is the major line. The HostBridge contract is separate and currently `1`. A
bridge that omits its optional `contractVersion` is treated as contract `1`,
so existing bridges keep working. `createAlohaUi()` rejects a bridge that
explicitly declares a different contract, omits a required method, or returns
an incomplete `capabilities()` object. The HTTP API remains [`v1`](../../src/Http/ApiVersion.php).

| Artifact | Current | Supported together | Authority |
| --- | --- | --- | --- |
| `alohasocial/core` | `0.7.0` | `0.7.x` | `src/Version.php` (`Version::CURRENT`); the release tag is the distribution version |
| `@alohasocial/ui` | `0.7.0` | `0.7.x` | `ui/package.json`; `UI_VERSION` in the build |
| `HostBridge` | contract `1` | contract `1`; an omitted `contractVersion` means 1 | `Version::HOST_BRIDGE_CONTRACT`; `HOST_BRIDGE_CONTRACT_VERSION` in `ui/src/version.ts` |
| HTTP API | `v1` | `v1` | `src/Http/ApiVersion.php` (`ApiVersion::CURRENT`) |
| Nextcloud host (`AlohaSocial/social`) | not in this repository | pin core `0.7.x`, UI `0.7.x`, bridge contract `1` when it adopts them | host repository; adapter work has not started |
| Standalone host (`AlohaSocial/server`) | does not exist yet | pin core `0.7.x`, UI `0.7.x`, bridge contract `1` when created | host repository; not created |

A host integration test can call
`Compatibility::assertSupported($installedUiVersion, $bridgeContract)` with the
version from its own npm lockfile. The UI performs the equivalent bridge check
at `createAlohaUi()`. Do not pair `dev-main` with a released UI version, and do
not claim support for a host version that its repository has not published.

The **currently enforced support policy** is lockstep: core and UI share the
same line, and a different line is rejected. Whether `@alohasocial/ui` may
publish an independent semver line is a maintainer policy decision, not a code
fact. The recommended option is to keep lockstep through 0.x and revisit the
question only at 1.0, with HostBridge contract `1` unchanged. Until that
decision, a pair outside the matrix is not supported.

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
    transactions: null,          // optional Core\Platform\Db\TransactionRunner; auto-detected when the database implements it
    httpClient: $http,           // Core\Platform\Http\HttpClient; BoundedHttpClient enables remote media
    signingKey: $keys,           // Core\Protocol\ActivityPub\RsaKeyPair
    localActors: $actors,        // Core\Domain\Federation\LocalActorDirectory
    localProfiles: $profiles,    // optional Core\Domain\Federation\LocalActorProfileProvider
    queue: $queue,               // Core\Jobs\JobQueue, durable
    activityLog: $activityLog,   // Core\Domain\Federation\ActivityLog, durable
    actorResolver: $resolver,    // optional; without it both inbox routes are 503
    remoteActors: null,          // optional RemoteActorFetcher; derived from a bounded HTTP client otherwise
    blockedActors: $policy,      // optional; instance-wide block policy
    blobStore: $blobs,           // optional; without it media routes are 503
    imageProcessor: $images,     // optional; default is GdImageProcessor
    hostResolver: $dns,          // optional; default is DnsHostResolver; guards all federation targets
    accountListRepository: null,  // optional; default is PdoAccountListRepository($database)
    atprotoAccounts: $atprotoAccounts, // optional; enables AT Protocol publishing
    atprotoPublisher: null,      // optional; default is XrpcAtprotoPublisher($http, $atprotoDpop)
    atprotoDpop: $dpop,          // optional; DPopResourceProofProvider for DPoP-bound OAuth tokens
    atprotoPublications: null,   // optional; default is PdoAtprotoPublicationRepository($database)
    atprotoEventSource: $relay,  // optional; enables inbound sync and the atproto.sync handler
    atprotoInbound: null,        // optional; default is PdoAtprotoInboundRepository($database)
    atprotoModeration: null,     // optional; default is AllowAllAtprotoContent
    atprotoBlobs: null,          // optional; builds blob URLs for the cached post projection
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
| `$app->followRequests`, `$app->profileUpdates` | Accept/reject a pending request for a locked account and publish a signed `Update(Person)` after the host changed the profile; `null` only if the host injected its own service |
| `$app->inbox` | `null` when inbound federation is not configured |
| `$app->accountMoves` | Receive a signed `Move`, start a local move and continue a batched migration; `null` without a `RemoteActorFetcher` |
| `$app->backfill` | Bounded outbox catch-up for a followed actor; `null` without an HTTP client |
| `$app->media` | Upload/presentation service; `null` when no `BlobStore` was supplied. Remote imports use the same validation and storage when the HTTP client is bounded |
| `$app->atproto` | AT Protocol publishing and remote-delete service; present even when disabled, but the route reports `503` until an account resolver and publisher are supplied |
| `$app->atprotoSync` | Inbound importer, cached feed and status; `null` unless an `AtprotoEventSource` was supplied. It never opens a socket itself |
| `$app->mastodon` | Mastodon compatibility service, including status creation with an optional poll, media upload/update/delete through the same `$app->media`, local follow/unfollow/relationship reads through `LocalRelationshipService`, private lists and list timelines through `AccountListService`, local notification listing/dismiss/clear/unread state through `NotificationService` and local status/actor search through the optional `PostSearchRepository`/`LocalActorSearch` capabilities; the routes are gated by `social/mastodon.api` and the host must pass token scopes on the request |

`queue` and `activityLog` are required on purpose. An in-memory queue loses
deliveries on restart, and an in-memory replay guard accepts the same activity
again after a restart. Tests may pass `InMemoryJobQueue` and
`InMemoryActivityLog`; production should pass the core's `PdoJobQueue` and
`PdoActivityLog` over the host's `Connection`.

A host `JobQueue` must implement `push`, `reserve`, `complete`, `fail`,
`deadLetter`, `pendingCount` and `deadLetterCount`. `deadLetter` is new in
`0.7.0` and is part of the upgrade: it records a terminal payload/type error
without another attempt. The core's `PdoJobQueue` is the reference
implementation; a custom adapter can delegate to it if the host has its own
table.

`Application::forHost()` uses `transactions` (or the database itself when it
implements `TransactionRunner`) for post creation/deletion. The boundary
covers the post and its side rows, the AT Protocol job and the delivery jobs;
without a runner the writes run directly, which is only appropriate for tests.
A non-PDO database adapter should implement both `Connection` and
`TransactionRunner`, or pass a dedicated runner.

`Application::inMemory()` remains the demo/test assembly. Do not use it as a
production bootstrap.

## The ports you have to provide

| Port | What the host must do | Core implementation |
| --- | --- | --- |
| `Clock` | Return the current UTC time | `SystemClock` |
| `Config` | Store `social/instance.url`, the optional `social/instance.*` metadata and the host's settings | `ArrayConfig` only for tests |
| `Db\Connection` | Execute parameterised SQL, fetch rows, join transactions and report the driver | `PdoConnection` if PDO is available |
| `Http\HttpClient` | Return non-2xx answers as `HttpResponse`; throw `HttpClientException` only on transport failure | `CurlHttpClient` |
| `Http\BoundedHttpClient` | Also cap the response body while reading and pin a validated address; required for remote media | `CurlHttpClient`; test `FakeHttpClient` / `LocalHttpClient` |
| `Network\HostResolver` | Resolve every A/AAAA answer so outbound delivery, actor/inbox discovery and remote media reject private addresses | `DnsHostResolver`; hosts may supply their DNS policy |
| `Storage\BlobStore` | Store media originals and thumbnails; pass it to `forHost()` to enable the media routes | `LocalBlobStore`, `InMemoryBlobStore` |
| `LocalActorDirectory` | Answer whether a user id is an account on this instance | host-specific |
| `LocalActorProfileProvider` | Optional: return the host-owned display name, bio, avatar/header URLs, up to four profile fields, `locked` and `hide_collections` for a local user; without it the actor document keeps the minimal shape | host-specific; `InMemoryLocalActorProfileProvider` for tests/tools |
| `LocalActorSearch` | Optional: search local account ids for `GET {mastodon.prefix}/search`; a directory that also implements this gets account search, otherwise the route answers `503 search_not_configured` for `type=accounts` | host-specific; `InMemoryActorDirectory` for tests/tools |
| `LocalActorCount` | Optional: report the number of local accounts for instance stats; without it the count is `0` | host-specific; `InMemoryActorDirectory` |
| `PostCount` | Optional: report live local posts for instance stats; without it the count is `0` | `InMemoryPostRepository`, `PdoPostRepository`; host adapter otherwise |
| `RemoteDomainCount` | Optional: report distinct live remote-post hosts for instance stats; without it the count is `0` | `InMemoryRemotePostRepository`, `PdoRemotePostRepository`; host adapter otherwise |
| `Jobs\JobQueue` | Reserve, complete, retry and dead-letter jobs durably; `0.7.0` adds `deadLetter()` | `PdoJobQueue`; `InMemoryJobQueue` for tests |
| `ActivityLog` | Claim an activity id exactly once, release it on failure, expire old ids | `PdoActivityLog`; `InMemoryActivityLog` for tests |
| `ActorResolver` | Resolve a signing key id to a remote actor; the assembly wraps it in `GuardedActorResolver`, which applies the outbound SSRF guard before the host adapter can fetch | `HttpActorResolver`, optionally wrapped in `CachingActorResolver` |
| `ActorInboxResolver` | Resolve a remote actor URL to its individual inbox for `Block`/`Undo(Block)`; an unavailable actor document queues nothing | `HttpActorInboxResolver` when the assembly has an HTTP client |
| `RemoteActorFetcher` | Fetch a target actor document for a Move and return its aliases and `movedTo`; the assembly derives one from a bounded HTTP client and its target guard, but a host may inject its own | `HttpActorResolver`; `InMemoryRemoteActorFetcher` for tests |
| `BlockedActorPolicy` | Optional instance-wide decision whether inbound content from an actor is refused | `NoBlockedActors` by default; host policy otherwise |
| `FollowPolicy` | Optional per-account decision whether an inbound Follow creates an edge | `AcceptAllFollows` by default; host policy otherwise |
| `InboxResolver` | Return the inboxes for a local author | `FollowerInboxResolver` by default |
| `RsaKeyPair` | Load the instance key from the host's secret store | `fromPems()` / `SecretBox` |
| `ImageProcessor` | Produce a bounded thumbnail; the default GD adapter is complete for local use | `GdImageProcessor` |
| `AtprotoAccountResolver` | Resolve a local user to a linked DID, handle, PDS URL and access token; the host may use `AtprotoSessionService` to refresh an OAuth session before returning it | none; host-specific |
| `AtprotoPublisher` | Write an `app.bsky.feed.post` record to a PDS; the default authenticated XRPC adapter is sufficient unless the host has its own client. Pass `atprotoDpop` when the linked account uses a DPoP-bound OAuth token, otherwise keep the bearer token | `XrpcAtprotoPublisher`; fake in tests |
| `AtprotoDeleter` | Delete the record this core published; the default XRPC adapter implements both write and delete | `XrpcAtprotoPublisher`; fake in tests |
| `AtprotoSessionStore` | Persist the host's OAuth session and replace it with the rotated tokens; keep tokens in the host's secret store | none; host secret store; `InMemoryAtprotoSessionStore` for tests |
| `AtprotoEventSource` | Own the relay/Jetstream/AppView transport, reconnect policy and normalization into bounded `AtprotoEventPage` values; the cursor must round-trip unchanged | none; host adapter; `FakeAtprotoEventSource` for tests |
| `AtprotoModerationPolicy` | Decide whether an inbound author or labeler is allowed | `AllowAllAtprotoContent` by default; host policy otherwise |
| `AtprotoBlobUrlBuilder` | Turn a DID and blob CID into a host-served media URL; returning `null` keeps the CID without a URL | `PdsAtprotoBlobUrlBuilder`; host URL builder otherwise |

### Locked accounts and profile fields

A host that implements `LocalActorProfileProvider` owns the profile values and
the `locked`/`hide_collections` flags. The core reads them for the actor
document, its `Update(Person)` and the Mastodon account; it does not write the
host's profile. When a locked account receives a `Follow`, the edge stays
pending until the host calls `$app->followRequests->accept()`/`reject()` or
routes `GET/POST /api/v1/follow-requests...`; accepted requests are the only
ones that receive content. After changing a profile, call
`$app->profileUpdates->publish($userId)` to queue the signed update to accepted
followers. `hide_collections` affects member pages only; the root count stays
public. See [the route reference](../reference/routes.md#locked-accounts-and-profile-fields).

### Account moves

`$app->accountMoves` receives signed `Move` activities, runs the resumable
follow migration and advertises `movedTo`/`alsoKnownAs` on the local actor
document after `moveLocalAccount($userId, $targetActorId)`. The move row lives
in `social_account_move`; the host only has to create that schema/table. A
Move never rewrites actor ids, key ids or post URLs. Accepted incoming
followers move to the target inbox, pending requests from the old actor are
dropped, and accepted outgoing follows are re-created with their previous
state. See [federation](federation.md#account-moves).

### Config keys

The required keys are:

```
social/instance.url    absolute base URL, no trailing slash required
social/instance.name   display name
```

The public instance response also reads optional metadata from the same `social`
app: `instance.description`, `instance.version`, `instance.contact_email` (with
`instance.email` as a fallback), `instance.contact_account`,
`instance.registrations`, `instance.approval_required`,
`instance.invites_enabled`, `instance.languages` and `instance.thumbnail`. The
booleans accept the normal `Config` truthy values; `languages` accepts a
comma-separated list or a JSON array. They have no required value and no
migration. `contact_account` is only advertised when the supplied
`LocalActorDirectory` contains that id; a host without a directory remains
responsible for the configured value.

The media keys are optional and documented in
[media](media.md#configuration). In particular, remote attachment ingestion
is controlled by `social/media.remote_fetch` (default on), with
`media.remote_max_bytes`, `media.remote_timeout_seconds` and
`media.remote_max_redirects` as limits. The Mastodon compatibility surface is
off by default; `social/mastodon.api` enables it and `social/mastodon.prefix`
selects the path (default `/api/mastodon/v1`). Media upload uses the same
`MediaService` and `BlobStore` as `/api/v1/media`; without one the route
answers `503 media_not_configured`. The prefix must not overlap `/api/v1` or
the protocol paths. Search adds no key: status search uses the same post
repository when it implements `PostSearchRepository`, and account search uses
`LocalActorSearch` when the supplied directory implements it. Instance stats
use the optional `LocalActorCount`, `PostCount` and `RemoteDomainCount`
capabilities; the normal assemblies wire the built-in repository and directory
adapters automatically, while a custom adapter that omits a capability reports
zero for that field. See [Mastodon-compatible API](mastodon-api.md).

`instance.url` is what every actor, post and activity id is built from. It must
be the public URL under which the core's routes are actually served. If that
URL includes a path prefix, the same prefix must be in the actor URL and the
host must strip it before passing the request to the kernel.

### Database

The core owns the schema classes, not the migration mechanism:

- `PostSchema` → `social_post`, `social_post_attachment` and `social_post_hashtag`
- `PollSchema` → `social_poll`, ordered `social_poll_option` and one `social_poll_vote` row per voter and poll
- `FavouriteSchema` → `social_favourite` (per-user post favourites; `(user_id, post_id)` primary key)
- `BookmarkSchema` → `social_bookmark` (private per-user bookmarks; `(user_id, post_id)` primary key, newest-first cursor index)
- `MuteSchema` → `social_mute` (private per-user mutes of local accounts or remote actor URLs; `(user_id, actor_id)` primary key, nullable `expires_at`, newest-first cursor index)
- `AccountListSchema` → `social_account_list` and `social_account_list_member` (private per-user lists and their known-local-account membership; `(list_id, account_id)` primary key)
- `FollowedHashtagSchema` → `social_followed_hashtag` (private per-user followed tags; `(user_id, hashtag)` primary key and newest-first cursor index)
- `RecommendationSchema` → `social_recommendation_preference`, `social_recommendation_feedback` and `social_recommendation_signal` (opt-in per-reader interests/consent, explicit feedback and bounded owner-scoped signals; snapshots are a cache and need no table)
- `StorySchema` → `social_story`, `social_story_view`, `social_story_reaction`, `social_story_reply` and `social_story_tombstone` (ephemeral followers-only stories, private receipts and a Delete-before-Add tombstone)
- `PostPinSchema` → `social_post_pin` (at most five explicit positions per owner, unique by owner and post)
- `MediaCollectionSchema` → `social_media_collection` and `social_media_collection_item` (owner title, description, audience and ordered post references)
- `BoostSchema` → `social_boost` (local reblogs; the id is the Announce id, with a unique `(user_id, post_id)` pair)
- `NotificationSchema` → `social_notification` (local favourite/follow/mention/reblog notifications; deterministic event id primary key, nullable `read_at` marker, policy status/audit columns and unread index)
- `NotificationPolicySchema`, `NotificationSenderSchema` and `ConversationMuteSchema` → policy overrides, held sender requests and private thread mutes
- `SearchIndexSchema` and `TrendSchema` → public discovery records with aliases/cache timestamps and bounded time-window hashtag rows
- `AttachmentSchema` → `social_attachment` (metadata, including nullable `source_url` with its unique index; bytes are in `BlobStore`)
- `FollowerSchema` → `social_follower` (includes the individual and nullable shared inbox plus `is_local` for local-to-local follows)
- `FollowingSchema` → `social_following` (includes `inbox_url`, `state` and `follow_activity_id`; accepted rows feed the public collection)
- `RemotePostSchema` → `social_remote_post` (remote posts and boosts) and `social_remote_post_attachment` (ordered remote-media links)
- `RemoteThreadSchema` → `social_remote_thread_post` (on-demand thread ancestors and direct replies, keyed by object id)
- `PostSchema` → `social_post`, `social_post_attachment`, `social_post_recipient`, `social_post_hashtag`
- `BlockSchema` → `social_block` (per-user blocks)
- `JobSchema` → `social_job` (optional `subject_type`/`subject_id` columns let an account deletion cancel its jobs)
- `AccountDeletionSchema` → `social_account_deletion` (durable deletion state, report and lease)
- `ActivityLogSchema` → `social_activity_log`
- `AtprotoPublicationSchema` → `social_atproto_publication` (AT Protocol idempotency rows)
- `AtprotoInboundSchema` → `social_atproto_sync_checkpoint`, `social_atproto_remote_record` and `social_atproto_remote_account` (host-fed inbound sync, opaque records/tombstones and account availability)

A host with PDO can wrap its handle in `PdoConnection` and call the schema
`create()` methods for a fresh installation. A host without PDO implements
`Connection` over its own database API. The SQL is deliberately plain and the
port is small; see [database](database.md).

For an existing installation, do **not** call `create()` on the existing
`social_job` and do **not** assume `social_account_deletion` exists. Run the
idempotent upgrade methods from the same host migration step:

```php
use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Jobs\JobSchema;

JobSchema::upgrade($connection);
AccountDeletionSchema::upgrade($connection);
```

`JobSchema::upgrade()` adds the nullable `subject_type`/`subject_id` columns and
the `social_job_subject` index to an old queue and leaves every pending row in
place. `AccountDeletionSchema::upgrade()` creates the tombstone table and its
state index. Both check the database catalogue before changing anything, so a
retried or partially applied migration is safe. Legacy queue rows keep
`NULL` subject columns; they are not guessed from their JSON payload and are
listed under `not_attributable` in the account export.

### For You recommendations

Recommendations are a host-neutral, optional read path. `RecommendationCandidateSource`,
`RecommendationRanker`, `RecommendationPreferenceRepository`,
`RecommendationFeedbackRepository`, `RecommendationSignalRepository` and the
optional `RecommendationSnapshotCache` are the ports; the default assembly wires
the PDO stores, `PdoRecommendationCandidateSource`, `StoredRecommendationEligibility`
and `DefaultRecommendationRanker`. A host can supply its own source or ranker
without changing the API.

**Performance budget.** One feed read loads at most 200 public candidate rows
and returns at most 40 items (`limit`, default 20). The first page loads four
times the requested limit, capped by the candidate bound; subsequent pages read
a snapshot rather than re-ranking. Ranking is linear in the candidate pool
(200 rows) plus the reader's newest 5,000 signals; there is no per-reader
network call and no cross-user model. Snapshots live for 300 seconds. The
default snapshot cache is process-local; a multi-worker host that wants cursor
continuity across workers must inject a shared `RecommendationSnapshotCache`,
otherwise a cursor from another worker is rejected as `invalid_cursor`.

**Evaluation limitations.** `for-you-v1` is a deliberately small deterministic
baseline: explicit interest matches, freshness, stored engagement, the reader's
own consented signals and feedback. It is not a trained relevance model, does
not learn from other readers, does not measure offline quality and its score is
not comparable across deployments. Hosts that need evaluation must measure their
own pool and ranking against their policy; the core only guarantees the hard
filters, owner isolation, stable cursor semantics and explainable reasons.
There is no ActivityPub or Mastodon status projection for recommendation pages:
they are an app-level read, and a client that needs statuses maps the returned
candidate target ids through the existing post/remote-post endpoints.

### Archives, pins and media collections

Archive is a reversible marker on `social_post.archived_at`, not a delete. An
archived post keeps its id and public detail route, dispatches no ActivityPub
`Delete`, and is omitted only from local public/author/followed/hashtag/search
and outbox listings. `GET /api/v1/posts/archived` is owner-only; archive and
unarchive are owner-only and idempotent.

Pins live in `social_post_pin` and are references, never copies: at most five
own public posts per account, with explicit one-based positions. Deleting,
archiving or widening the audience of a post removes its pin; the stable
`featured` `OrderedCollection` at `/@{handle}/featured` is derived from the live
pins and re-checks author, visibility, archive and deletion state on every
read. Unpinning never deletes the post.

`MediaCollectionService` owns collections and their ordered items. A collection
has an owner, title, optional description and an audience of public, unlisted or
followers (never direct). Only the owner's image/video posts can be added, and
every read resolves each item through `PostService::getVisible()` for the actual
viewer; item counts and the cover are derived from that visible set. Deleting a
collection removes its membership rows only. `GET /@{handle}/collections`
is viewer-aware and never enumerates unlisted collections.

### Stories

Stories are a separate, optional domain rather than posts. The ports are
`StoryRepository`, `StoryAccess` and `StoryService`; the default assembly wires
`InMemoryStoryRepository` or `PdoStoryRepository`, the owner/follower policy,
the existing `MediaService`, the follower and following repositories and the
job queue. `StoryService::create()` accepts only an owned image/video attachment
that no post or other story uses, stores a 1-24 hour expiry, queues the
Pixelfed-compatible `Add` to each accepted follower's inbox and schedules an
idempotent `story.expire` job.

Every read goes through `StoryAccess`: the owner, or a local reader with an
accepted follow of the owner (and, for a remote owner, an accepted outgoing
follow), may see the story and its bytes. Expired and deleted rows are refused
even if a stale repository read returns them. Views, reactions and replies are
private receipts; replies are never converted into posts. The media route is
authenticated and returns `Cache-Control: private, no-store`, so a host must not
front it with a shared cache or an unauthenticated static path.

Inbound `Add` requires an actor followed by a local account, an object type of
`Story`, no public audience and one http(s) attachment URL. The stored expiry is
clamped to local policy. `Delete` is matched by object id; an unknown object is
remembered as a tombstone so a replayed `Add` cannot resurrect it. The
`story.expire` worker job sweeps expired rows in bounded batches and removes
local media unless a post still links the attachment. A host that already has
the social tables must create the five `StorySchema` tables through its own
migration path; there is no post-row backfill.

If `social_following` already exists from an earlier core version, add the
`inbox_url VARCHAR(512) NOT NULL` column through the host's migration path.
For the outgoing follow lifecycle, also add
`state VARCHAR(16) NOT NULL DEFAULT 'accepted'` and nullable
`follow_activity_id VARCHAR(512)` with a unique index. Existing rows are
accepted follows; new rows start pending. The host worker must use the
`JobHandlerRegistry` returned by `Application`, which already registers
`federation.follow` (Follow and Undo) next to `federation.deliver`.
If `social_follower` exists from an earlier core version, add the nullable
`shared_inbox_url VARCHAR(512) NULL` column. Existing rows need no backfill and
fall back to their individual inbox; see
[shared inboxes](shared-inboxes.md#migration). Also add
`is_local SMALLINT NOT NULL DEFAULT 0`; existing rows stay remote, and only the
Mastodon-compatible local follow route writes `1`. Without the column, follower
reads and follow/unfollow fail.
If `social_attachment` predates remote media, add nullable `source_url
VARCHAR(512)` and its unique index, and create
`social_remote_post_attachment` from `RemotePostSchema::statements()`.
For thread/context fetching, create `social_remote_thread_post` from
`RemoteThreadSchema::statements()` and wire a `RemoteThreadRepository` into
`Application::forHost()`; a host that does not create the table must not expose
the route. For local Mastodon favourites, create `social_favourite` from
`FavouriteSchema::statements()` before exposing the favourite/unfavourite
routes; existing posts need no backfill, and deleting a post leaves the row for
the host's cleanup policy. For private bookmarks, create `social_bookmark`
from `BookmarkSchema::statements()` before exposing the bookmark routes or
list; it has no public count, no notification and no backfill, and a
soft-deleted post leaves its bookmark row for the host's cleanup policy. For
local mutes, create `social_mute` from `MuteSchema::statements()` before
exposing the mute routes, home-timeline filtering or notification filtering;
active rows are filtered on read, an expired row is ignored without a cleanup
job, and there is no backfill. For private lists and their list timeline,
create `social_account_list` and `social_account_list_member` from
`AccountListSchema::statements()` before exposing the list routes or the
exclusive home-timeline filter; deleting a list removes its member rows and
there is no backfill or remote membership. For followed hashtags, create
`social_followed_hashtag` from `FollowedHashtagSchema::statements()` before
exposing `followed_tags` or `timelines.followed_tags`; the table is private
and has no backfill, and the timeline additionally needs
`social_post_hashtag` rows for the posts it should match. For
local boosts/reblogs, create `social_boost`
from `BoostSchema::statements()` before exposing the reblog routes or status
counts; the unique pair is the idempotency boundary, and a soft-deleted post
leaves its boost for the host's cleanup policy. For local polls, create `social_poll` and
`social_poll_option` from `PollSchema::statements()` before accepting a `poll`
parameter or serving a status whose repository reads the poll tables. Create
`social_poll_vote` before enabling the vote route; without it, status and poll
reads still work but omit the viewer's vote state, while the vote route answers
`503 polls_not_configured`. There is no backfill, poll rows are written in the
same transaction as their post, and a vote advances the poll and option
counters in the same transaction as its row. For
local notifications, create `social_notification` from
`NotificationSchema::statements()` before exposing the notification routes;
there is no backfill from older favourites or followers. Existing
listing-only tables need `read_at VARCHAR(20) NULL` and the
`(recipient_id, read_at, created_at, id)` index before dismiss, clear or
unread-count are enabled; old rows remain unread. Repeating an event cannot
duplicate a row. A post or follow deletion deliberately does not delete the
historical notification; the core cleanup job removes read rows after the
configured retention. For follow/unfollow and
relationship reads, add
`is_local` as described above and pass a local account directory so
`Application` assembles `LocalRelationshipService`; without it those routes
answer `503 follows_not_configured`. For AT Protocol publishing, create
`social_atproto_publication` from `AtprotoPublicationSchema::statements()` and
supply an `AtprotoAccountResolver`;
supplying no resolver leaves the existing route at `503 atproto_not_configured`
without writing anything. For inbound sync, create the three tables from
`AtprotoInboundSchema::statements()` and supply an `AtprotoEventSource`; there
is no backfill from the outbound publication table, and a host without a
source needs no inbound tables at all. `0.7.0` adds no table or column and needs no data
migration; it only requires the host's queue adapter to gain `deadLetter()`
and its database adapter to expose `TransactionRunner` for atomic post writes.
There is no migration runner, but `0.6.0` ships a
retention job instead of leaving expiry entirely to each host. Register
`JobType::CLEANUP` through `Application::forHost()`'s normal worker and
schedule the worker (for example with cron):

```php
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Service\Cleanup\CleanupOptions;

$app->queue->push(JobType::CLEANUP, CleanupOptions::fromArray([
    'kind' => 'all',
    'limit' => 100,
])->toArray());
```

A one-off run can call `$app->cleanup->run(CleanupOptions::fromArray([...]))`
directly. The job needs no new table; it uses the existing attachment,
`social_post_attachment`, `social_remote_post_attachment`, notification and
remote-thread schemas. A host without a `BlobStore` gets a skipped media
result instead of a partial delete, while notifications and thread caches
still run. See
[retention cleanup](retention-cleanup.md) for the exact rules and failure
behaviour. A host that only wants the thread cache to expire can still call
`RemoteThreadRepository::deleteFetchedBefore()` itself.

### Durable queue and activity log

The core now supplies the two adapters a production host needs, both over the
same `Connection`:

```php
use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\PdoJobQueue;

JobSchema::create($connection);
AccountDeletionSchema::create($connection);
ActivityLogSchema::create($connection);

// Existing installation instead: JobSchema::upgrade($connection);
// AccountDeletionSchema::upgrade($connection);
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
| `subject_type` | `VARCHAR(32)` | yes |
| `subject_id` | `VARCHAR(255)` | yes |

Indexes: `social_job_due (available_at, id)`, `social_job_dead (dead_at)`,
`social_job_subject (subject_type, subject_id)`.

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
    scopes: $hostToken?->getScopes() ?? [], // token scopes for compatibility routes
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
`401 authentication_required` response. For the optional Mastodon
compatibility routes, the host also maps the token scopes onto
`Request::$scopes`; `RequireScopes` compares them with the route's
`requiredScopes` and answers `403 insufficient_scope`. A parent scope grants
its children; the host must not synthesize a parent from a granular token.

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
timeout and redirect guards. It does not contain an admin UI, the full Mastodon server API or an
object-storage adapter. The local Mastodon subset, AT Protocol remote delete,
the OAuth session-refresh exchange and the authorization-code/PAR/revocation
protocol client are implemented; the account-linking UI/callback, flow store
and the credential store remain host work. It does provide a
bounded outbox backfill for missed `Create`/`Announce` activities. A host that
needs the remaining features either keeps its existing implementation for them
or waits for the corresponding core milestone. See [media](media.md) for the
storage and remote-fetch contract and
[retention cleanup](retention-cleanup.md) for orphan media, read
notifications and the thread cache; an object-storage adapter is still a host
decision.

The integration is therefore incremental: move one slice (for example posts
and timelines), keep the old path for everything else, and never run two
owners for the same handle. Milestone order and current status are in the
[roadmap](../roadmap.md); this guide only describes the mechanics.

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
screens use the [`HostBridge`](shared-ui.md) from `@alohasocial/ui` under
`ui/`, never a host global.
