# AT Protocol publishing

The core owns a deliberately small, public, text-only AT Protocol publishing
foundation. It does not own account linking, OAuth sessions, the AppView or a
full repository client; those remain in the host or in a future standalone
client. This guide describes the implemented seam, its limits and what a host
must provide.

## What is implemented

- `Protocol\Atproto\AtprotoPostRecord` builds an `app.bsky.feed.post` record
  with `$type`, `text` and `createdAt`. A local content warning becomes the
  `com.atproto.label.defs#selfLabels` label `content-warning`.
- `Protocol\Atproto\Tid` creates a valid 13-character `base32-sortable` record
  key. It is deterministic for one local post and account, so a retry updates
  the same PDS record through `com.atproto.repo.putRecord` instead of creating
  a duplicate.
- `Protocol\Atproto\XrpcAtprotoPublisher` authenticates with the account's
  bearer token and calls `POST /xrpc/com.atproto.repo.putRecord` on the PDS at
  the account's `pdsUrl`. It accepts only an `at://` URI returned by the PDS.
- `Domain\Atproto\AtprotoAccount` validates the DID, lowercase handle, absolute
  http(s) PDS URL and access token. `AtprotoAccountResolver` is the host port
  that maps a local user id to that account.
- `Domain\Atproto\AtprotoPublication` and its repository (in-memory and PDO
  behind one contract test) store the local idempotency record in
  `social_atproto_publication`.
- `Service\Atproto\AtprotoPublishService` schedules an automatic attempt after
  a post is written and implements the explicit publish operation. The
  `atproto.publish` job is handled by `AtprotoPublishHandler`.

## What is deliberately not here

Only public posts are mirrored. Posts with attachments, non-public posts and
text over the AT Protocol limits (300 graphemes or 3000 bytes) are skipped by
the automatic hook and refused by the explicit route; they are never silently
truncated. There is no partial record, blob upload, image embed, edit, delete,
thread/reply, facet/link detection, AppView read, firehose consumer, CAR/MST
repository implementation or OAuth session refresh in this slice.

Account linking, the account/token store, token refresh and PDS selection stay
in the host. The core's `AtprotoAccountResolver` is intentionally a port, not a
credential table. A host that has no resolver still serves the route, but it
answers `503 atproto_not_configured`.

## Configuration

| App | Key | Default | Meaning |
| --- | --- | --- | --- |
| `social` | `atproto.publish` | `false` | Enables automatic scheduling and the explicit publish route. `false`/`0`/`off`/`no` makes the route answer `503 atproto_publish_disabled` and prevents new jobs. Existing publication rows are retained. |

The feature is off by default, so upgrading the package does not start
outbound network writes. Turning it on requires both an account resolver and an
`AtprotoPublisher`; the production assembly supplies `XrpcAtprotoPublisher`
from the host's `HttpClient`, while tests and tools can supply a fake.

## Host wiring

```php
use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;

final class HostAtprotoAccounts implements AtprotoAccountResolver
{
    public function resolve(string $localUserId): ?AtprotoAccount
    {
        // Read the host's linked account and refresh its token if needed.
        // Never put the access token in a log or an API response.
        return $this->accounts->forUser($localUserId);
    }
}

$app = Application::forHost(
    // ...the normal host ports...
    atprotoAccounts: new HostAtprotoAccounts($accounts),
    // Optional: defaults to XrpcAtprotoPublisher($httpClient).
    atprotoPublisher: null,
    // Optional: defaults to PdoAtprotoPublicationRepository($database).
    atprotoPublications: null,
);
```

`Application::inMemory()` accepts the same three optional arguments for tests,
tools and the demo. Without `atprotoAccounts` the service is not configured;
`social/atproto.publish` can be on while the route still answers `503
atproto_not_configured`, which makes a half-finished deployment visible rather
than pretending to publish.

The host must create `AtprotoPublicationSchema` (see [database](database.md))
and grant the PDS account permission to write `app.bsky.feed.post` records.
The core never reads or writes the host's credential store.

## Flow

1. `PostService::create()` stores the post, then calls
   `AtprotoPublishService::schedule()`. Scheduling is a no-op when the feature
   is disabled, the assembly is incomplete, the post is not public, it has
   attachments, its text is too long, or the author has no linked account.
2. When eligible, a durable `atproto.publish` job with `{user_id, post_id}` is
   queued after the post row exists. `PostService` never performs the network
   write inline.
3. The worker calls `publish()`, resolves the account, builds the record and a
   deterministic TID, then calls `putRecord`.
4. Only after the PDS returns an `at://` URI does the core insert the local
   publication row. A PDS or transport failure therefore leaves no publication
   row; the job is retried with the queue's normal backoff and the same record
   key.
5. A repeat, whether from a retried job or the explicit route, returns the
   stored publication without another PDS request. `putRecord` is also an
   upsert, so even a lost response does not create a second record with the
   same key.

The explicit route is independent of the automatic hook. It is useful for a
retry button or a host that wants to opt in per post:

```http
POST /api/v1/posts/{id}/atproto
Authorization: Bearer …
```

`200`:

```json
{"publication": {
  "post_id": "…",
  "rkey": "3mabcdefghijk",
  "uri": "at://did:plc:alice/app.bsky.feed.post/3mabcdefghijk",
  "published_at": "2026-11-11T10:00:00Z"
}}
```

Errors are stable and distinguishable:

| Status | Code | Meaning |
| --- | --- | --- |
| 401 | `authentication_required` | No authenticated user |
| 404 | `not_found` | Missing, deleted or another account's post |
| 409 | `atproto_account_not_connected` | The host has no linked account for this user |
| 422 | `atproto_post_unsupported` | `error.fields.reason` is `not_public`, `attachments_unsupported` or `text_too_long` |
| 502 | `atproto_publish_failed` | The PDS refused the record or could not be reached |
| 503 | `atproto_publish_disabled` | `social/atproto.publish` is off |
| 503 | `atproto_not_configured` | No resolver and publisher were assembled |

No error includes a token or a PDS response body. A refusal writes no
publication row and does not modify the local post.

## Data and cleanup

`AtprotoPublicationSchema` creates `social_atproto_publication`:

| Column | Type | Notes |
| --- | --- | --- |
| `local_user_id` | `VARCHAR(255)` | part of the primary key |
| `post_id` | `VARCHAR(64)` | part of the primary key |
| `rkey` | `VARCHAR(13)` | deterministic TID; unique per account |
| `uri` | `VARCHAR(512)` | canonical AT URI returned by the PDS |
| `published_at` | `VARCHAR(20)` | canonical UTC timestamp |

The primary key is `(local_user_id, post_id)` and `(local_user_id, rkey)` is
unique. The core does not delete a remote record when a local post is deleted;
that would need a separate `com.atproto.repo.deleteRecord` operation and is
outside this slice. A host can remove the local idempotency row with
`AtprotoPublicationRepository::removeForPost()` when it deliberately wants a
later publish to create a new local mapping. It must delete the remote record
through its own AT Protocol client first if that is the intent; removing only
the row does not change the PDS.

## Testing

`AtprotoPublishTest` drives the complete local path through the real HTTP
kernel: post creation, queued job, fake XRPC adapter, publication row and JSON.
It covers automatic and explicit publication, repeat idempotency, an unsupported
post, a disabled feature, auth, an unknown post, a missing account, content
warning labels and a PDS failure that leaves no row. `AtprotoPublishServiceTest`
covers the schedule skip rules for attachments, non-public posts and long text;
`XrpcAtprotoPublisherTest` checks the exact XRPC request and malformed/refused
responses; `TidTest` and the repository contract cover the protocol and
persistence primitives.

## Remaining external work

The full AT Protocol stack is still host/app work:

- account linking UI and callback, OAuth or app-password flow, token refresh,
  revocation and per-account PDS discovery;
- a full repository client (CAR/MST, record listing, delete/update), AppView
  reads, firehose consumption and blob upload;
- media embeds and image processing for AT Protocol;
- reply/thread mapping, facets, mentions and link cards;
- migrating the existing AT Protocol implementation out of the Nextcloud app
  and deleting remote records on local deletion.

Those are tracked in the [roadmap](../roadmap.md) as the remaining part of the
AT Protocol stack, not as part of this publishing foundation.
