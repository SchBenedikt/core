# AT Protocol publishing and inbound sync

The core owns a deliberately small, public, text-only AT Protocol publishing
foundation. It owns deterministic record writes, the matching remote record
delete for mirrored posts, the OAuth refresh exchange for a host-owned session,
the authorization-code/PAR/revocation protocol client and an inbound importer
for normalized relay/Jetstream/AppView events. It does not own the
account-linking UI/callback, the credential store, PDS selection, the event
transport or a full repository client; those remain in the host or in a future
standalone client. This guide describes the implemented seams, their limits and
what a host must provide.

## What is implemented

- `Protocol\Atproto\AtprotoPostRecord` builds an `app.bsky.feed.post` record
  with `$type`, `text` and `createdAt`. A local content warning becomes the
  `com.atproto.label.defs#selfLabels` label `content-warning`.
- `Protocol\Atproto\Tid` creates a valid 13-character `base32-sortable` record
  key. It is deterministic for one local post and account, so a retry updates
  the same PDS record through `com.atproto.repo.putRecord` instead of creating
  a duplicate.
- `Protocol\Atproto\XrpcAtprotoPublisher` calls
  `POST /xrpc/com.atproto.repo.putRecord` on the PDS at the account's `pdsUrl`
  and accepts only an `at://` URI returned by the PDS. Without a proof provider
  it authenticates with the account's bearer token; with a
  `DpopResourceProofProvider` it sends a DPoP resource request instead, which
  is what an AT Protocol OAuth access token needs.
- `Domain\Atproto\AtprotoAccount` validates the DID, lowercase handle, absolute
  http(s) PDS URL and access token. `AtprotoAccountResolver` is the host port
  that maps a local user id to that account.
- `Domain\Atproto\AtprotoPublication` and its repository (in-memory and PDO
  behind one contract test) store the local idempotency record in
  `social_atproto_publication`.
- `Domain\Atproto\AtprotoFollowRecord` and `Service\Atproto\AtprotoFollowService`
  write `app.bsky.graph.follow` records for remote DIDs. The service first
  stores a pending local projection, uses an account-and-subject deterministic
  rkey so concurrent retries converge on one PDS record, verifies the record
  returned by `getRecord`, and only deletes records belonging to the same DID
  that created them. Hosts persist this projection with
  `AtprotoFollowSchema` and supply an `AtprotoFollowRepository` to
  `Application::forHost()`; in-memory assembly uses an in-memory repository.
- `Service\Atproto\AtprotoPublishService` schedules an automatic attempt after
  a post is written and implements the explicit publish operation. The
  `atproto.publish` job is handled by `AtprotoPublishHandler`.
- `Domain\Atproto\AtprotoDeleter` is the delete-side port.
  `XrpcAtprotoPublisher` implements it with an authenticated
  `POST /xrpc/com.atproto.repo.deleteRecord`; the delete is idempotent, so a
  missing record (404) and a retry after a lost response both succeed.
  `AtprotoPublishService::scheduleDelete()` queues an `atproto.delete` job only
  when a publication row exists; `AtprotoDeleteHandler` performs it and removes
  the local row only after the PDS accepted the delete.
- `Domain\Atproto\AtprotoOAuthSession` validates a token endpoint, client id,
  DID, rotating access/refresh tokens, the mandatory `atproto` scope and the
  expiry. `AtprotoSessionRefresher` is the refresh port;
  `XrpcAtprotoSessionRefresher` implements the form-encoded token request with
  a fresh DPoP proof and one `use_dpop_nonce` retry. `Es256DpopProofProvider`
  is the default ES256 proof implementation. `AtprotoSessionService` refreshes
  a session inside a 60-second expiry leeway and saves the rotated pair through
  the host-owned `AtprotoSessionStore`. `InMemoryAtprotoSessionStore` is the
  test/tool fake; the host keeps the real tokens in its secret store.
- `Protocol\Atproto\AtprotoOAuthMetadataResolver` fetches the PDS
  `/.well-known/oauth-protected-resource` document, follows its exactly one
  `authorization_servers` entry, and validates the authorization-server
  metadata: issuer binding, PAR, issuer response parameter, client metadata,
  authorization code, refresh token, S256 PKCE, `none` client authentication,
  ES256 DPoP and the mandatory `atproto` scope. It only follows the PDS URL
  chosen by the host and that PDS's single advertised authorization server;
  the browser cannot supply either endpoint.
- `Domain\Atproto\AtprotoEventSource`, `AtprotoEvent` and
  `Service\Atproto\AtprotoSyncService` import bounded relay/Jetstream/AppView
  pages with a durable cursor, sequence/revision ordering, tombstones, account
  revocation, moderation and an offline cached post feed. The `atproto.sync`
  job runs one page; the host owns the transport. See [inbound sync](#inbound-sync).
- `Protocol\Atproto\XrpcAtprotoOAuthClient` starts a flow with a PAR request
  (`response_type=code`, PKCE S256, `dpop_jkt`, client id, redirect URI, scope,
  state and optional `login_hint`), returns the browser authorization URL and
  the state/verifier/DPoP nonce, exchanges the code after checking the callback
  `iss` and the token `sub`, and revokes the refresh token at the advertised
  revocation endpoint. Every token request carries a fresh DPoP proof and
  retries exactly one `use_dpop_nonce` challenge. `DpopKeyThumbprint` is the
  optional proof-provider capability used for `dpop_jkt`;
  `Es256DpopProofProvider` implements it with the RFC 7638 thumbprint.

## What is deliberately not here

Only public posts are mirrored. Posts with attachments, non-public posts and
text over the AT Protocol limits (300 graphemes or 3000 bytes) are skipped by
the automatic hook and refused by the explicit route; they are never silently
truncated. Facets, reply references, image/video embeds, likes/reposts and an
AppView timeline read are implemented (see below). The core also imports
normalized inbound relay/Jetstream/AppView pages through
`AtprotoEventSource`; the host owns the WebSocket/HTTP consumer and hands over
bounded event pages. This external-PDS publishing path does not include a
built-in firehose consumer; the optional self-hosted native PDS does provide
the full CAR/MST repository and `bin/pds-firehose` WebSocket server (see
[native PDS](atproto-repository.md)). An interaction with a post this instance
never published is not here. Remote delete is implemented for records the core
itself published; deleting arbitrary PDS records is not, and so is deleting an
uploaded blob.

OAuth session refresh and the authorization-code/PAR/revocation protocol are
implemented. The browser redirect, the short-lived flow store keyed by state,
the linked-account UI, the account/token store and PDS selection stay in the
host. The core's `AtprotoAccountResolver` is intentionally a port, not a
credential table, and it creates no linked-account or OAuth-flow row. This
OAuth session is separate from application login and does not reopen the
host-owned identity boundary in [ADR 0006](../adr/0006-host-owned-accounts.md).
A host
that has no resolver still serves the publish route, but it answers `503
atproto_not_configured`.

## Facets and replies

`Protocol\Atproto\AtprotoFacets` derives the record's `facets` from the post
text: `app.bsky.richtext.facet#tag`, `#mention` and `#link`. Offsets are
**byte** offsets in the UTF-8 text because the lexicon indexes bytes; a
character index would point inside an umlaut or an emoji. Links are detected
first, then mentions, then tags, and a candidate that overlaps an accepted span
is dropped, so a `#tag` inside a URL path cannot become a second facet. Links
lose trailing sentence punctuation and closing brackets, tags need a word
boundary and stop at punctuation, and an `@handle` only counts with a dot and
outside an email address or a URL.

A mention needs its DID. `AtprotoIdentityResolver` is the port;
`XrpcAtprotoIdentityResolver` calls `com.atproto.identity.resolveHandle` on the
publishing account's PDS. A handle that does not resolve — or an assembly
without a resolver — refuses the post with the stable reason
`mention_unresolved`: the core never writes a mention facet without a DID. The
automatic hook skips such a post and logs it; the explicit route answers `422`.

`Protocol\Atproto\AtprotoReplyBuilder` builds the `reply` object from the
parent AT URI. The parent is read with `com.atproto.repo.getRecord`
(`XrpcAtprotoRecordResolver`) because its `cid` cannot be derived from the URI.
A parent that is itself a reply carries its own `root`; that root is inherited,
so a deep thread keeps one root instead of re-rooting at the direct parent.
Only a root reference without a usable `cid` is looked up separately. An
unreadable parent or root refuses the post with `reply_parent_unresolvable` or
`reply_root_unresolvable`. The service only replies to a parent that was itself
published from this instance (`social_atproto_publication`): a local-only parent
is `reply_parent_not_published`, and an assembly without a reply builder is
`replies_unsupported`.

`AtprotoPostRecord::fromText($text, $publishedAt, $contentWarning, $reply,
$facets)` and `fromPost($post, $reply, $facets)` assemble the record that
`AtprotoPublishService` hands to the publisher, so a caller supplies the text,
an optional parent URI and the facet inputs rather than a pre-built payload.

## Media embeds

`Protocol\Atproto\AtprotoBlobUploader` is the upload port;
`XrpcAtprotoBlobUploader` sends the raw bytes to
`com.atproto.repo.uploadBlob` with the media type as the request Content-Type,
over the same bearer/DPoP transport as the record writer, and reads the blob
reference (`$type: blob`, `ref.$link`, `mimeType`, `size`) from the answer.
`AtprotoEmbed` validates everything before the first upload and then builds
`app.bsky.embed.images` or `app.bsky.embed.video`.

| Rule | Value | Refusal reason |
| --- | --- | --- |
| Image types | `image/jpeg`, `image/png`, `image/webp` | `blob_mime_unsupported` |
| Video types | `video/mp4` | `blob_mime_unsupported` |
| Image size | at most 1,000,000 bytes | `blob_too_large` |
| Video size | at most 50,000,000 bytes | `blob_too_large` |
| Empty file | never uploaded | `blob_missing` |
| Images per post | at most 4 | `embed_too_many_images` |
| Videos per post | at most 1 | `embed_too_many_videos` |
| Images and video together | never | `embed_mixed_media` |
| Alt text per image | required, may be empty | `embed_alt_missing` |
| Alt text length | at most 2000 characters | `embed_alt_too_long` |
| Aspect ratio | both dimensions, 1–10000 | `embed_aspect_ratio_invalid` |

An empty alt text is allowed and means the image is decorative; a *missing*
alt text refuses the record, so a caller cannot forget it by accident. A
video's alt text is optional in the lexicon and is omitted when absent.
`aspectRatio` is emitted only when both dimensions are present. Alt text and
aspect ratio are the caller's data: the core does not guess either from the
bytes.

`AtprotoPublishService::publish($userId, $postId, $media)` accepts an explicit
media list. When it is empty, the service resolves the post's stored
attachments through `MediaService`, verifies owner, family, count, alt text
and recorded byte sizes, then reads the bounded image/video set. This lets the
automatic post hook and a manual publish use the same stored attachments; a
host without media storage can still publish text posts and skips attachment
posts. Every refusal ends the whole post: no record and no partial embed is
written. A blob uploaded before a later upload failed stays on the PDS without
a record — the core does not delete it, because it never deletes a blob the
account might use elsewhere.

## Reposts and likes

`AtprotoInteractionService` writes `app.bsky.feed.like` and
`app.bsky.feed.repost` records for a post this instance already published:
`like()`/`unlike()` and `repost()`/`undoRepost()`.

- The subject is a `com.atproto.repo.strongRef`: record URI plus CID. The URI
  comes from the publication row, the CID is read with `getRecord` — it cannot
  be derived locally. A post without a publication is refused with
  `interaction_subject_not_published`; a subject the PDS cannot return is
  refused with `interaction_subject_unresolvable`. Neither writes a record.
- The record key is derived from the subject post and the interacting account.
  A repeated like or repost therefore updates the same record, and the undo
  deletes exactly that key without a local interaction table. Liking again after
  an undo recreates the same key.
- `AtprotoPublisher::publish()` and `AtprotoDeleter::delete()` take the
  collection as their last argument (default `app.bsky.feed.post`), so one
  adapter writes and removes all three record types. A non-NSID value is
  rejected before any request.
- The service is host-called, like session refresh: it is not wired into the
  internal favourite or boost path, so a host decides when a local interaction
  becomes an AT Protocol record. Liking a Bluesky post from the network needs
  the AppView reads that are still open.

## Profile

`AtprotoProfileService` writes `app.bsky.actor.profile` with record key `self`
and removes it again. The host passes the fields it wants: `displayName`
(1–64 characters), `description` (up to 256), and an optional avatar and banner
as `AtprotoMedia`. Both images go through the same blob uploader as post media
and are checked **before** the first upload — only JPEG and PNG, at most one
megabyte each — so a refused banner leaves the previous record untouched. The
record omits absent fields; an empty string is a deliberate clear.

## Reading a Bluesky timeline

`AtprotoTimelineReader` is a read port; `XrpcAtprotoTimelineReader` calls
`app.bsky.feed.getTimeline` with the account's PDS and token, using the same
bearer/DPoP transport. It returns `AtprotoTimelineItem` values (uri, cid,
author DID/handle/display name, text, createdAt, reply/repost/like counts, the
raw embed and indexedAt) and the opaque `cursor` for the next page; the limit is
clamped to 1–100. A refused request raises `timeline_unavailable`, an
unreadable body `timeline_invalid_response` — an empty page would falsely say
"no posts". Caching, rendering and mapping DIDs to local users stay with the
host.

## Inbound sync

`Domain\Atproto\AtprotoEventSource` is the host transport port. It names one
stable source, pulls a bounded page for an opaque cursor and reports its own
health. Relay commits, Jetstream events and AppView reconciliation all become
the same `AtprotoEvent` shape: a validated DID, an account or record kind, the
record collection/rkey/CID/revision/sequence, an opaque JSON body, optional
labels and an account status. The core never opens a WebSocket or polls an
AppView itself.

`Service\Atproto\AtprotoSyncService` owns the replay-safe part:

- One page is pulled outside the write transaction, then all records and the
  checkpoint commit together. A crash repeats the page instead of losing it.
- A record is ordered by `sequence` first, otherwise by `rev`. Older events are
  skipped, an equal sequence/revision with the same CID is a duplicate, and an
equal key with a different CID is a conflict that is counted but not applied.
- Account events are authoritative. An inactive or taken-down account purges
  all its records, and later record events are skipped until the account is
  active again. A handle cannot be rebound to another DID.
- A `delete` event stores a tombstone. A later create/update of the same URI
  can restore it; a second delete is a no-op.
- A sequence gap marks the checkpoint `reconcile_required`; the next bounded
  backfill clears it only when it reaches the end of the source page.
- A failed source poll marks the checkpoint `stale` but leaves cached posts
  readable. `feed()` uses only stored rows and reports that staleness; it never
  makes a network request.
- `app.bsky.feed.post` is projected to text, reply/root references, image or
  video blobs and validated external cards. All other collections stay opaque.
  A malformed or unsafe post is skipped, not guessed at.
- Labels from a blocked labeler and authors refused by
  `AtprotoModerationPolicy` are skipped. `AllowAllAtprotoContent` is the
  default; a host supplies its policy through the assembly.

`backfill()` is bounded to 10 pages of at most 100 events and defaults to a
30-day publication window (maximum 3650 days). Account events and tombstones
are applied even outside the window, so a revocation is not lost because its
timestamp is old. `status()` combines the persisted checkpoint with the
source adapter's health; `feed($did, $limit, $cursor)` returns cached posts
newest first with an opaque cursor, at most `100` per page.

`JobType::ATPROTO_SYNC` (`atproto.sync`) runs one page from the worker with an
optional integer `limit` between 1 and 100. A source/transport failure is left
to the queue backoff. A source mismatch or identity conflict is logged and the
job completes, so one poison page does not block the worker forever. The
service is exposed as `$app->atprotoSync` when an `AtprotoEventSource` was
supplied.

`AtprotoSyncException` exposes a stable `reason`: `atproto_source_unavailable`
(retryable), `atproto_store_failed` (retryable), `atproto_source_mismatch`
(terminal for the page), `atproto_identity_conflict` (terminal until an
operator resolves the handle) and `atproto_invalid_cursor` (caller error). No
reason includes a token, a record body or a source URL.

`AtprotoInboundSchema` creates `social_atproto_sync_checkpoint` (one cursor and
outcome row per source), `social_atproto_remote_record` (opaque records and
tombstones keyed by source and URI) and `social_atproto_remote_account`
(handle, status, active flag). The PDO adapter is the production store; the
in-memory adapter is for tests and tools. No schema is shared with the
outbound `social_atproto_publication` table.

## Configuration

| App | Key | Default | Meaning |
| --- | --- | --- | --- |
| `social` | `atproto.publish` | `false` | Enables automatic scheduling, the explicit publish route and the matching remote delete for published posts. `false`/`0`/`off`/`no` makes the route answer `503 atproto_publish_disabled` and prevents new publish/delete jobs. Existing publication rows are retained. This key does not gate OAuth session refresh; that is a host-called protocol operation. |

The feature is off by default, so upgrading the package does not start
outbound network writes. Turning it on requires both an account resolver and an
`AtprotoPublisher`; the production assembly supplies `XrpcAtprotoPublisher`
from the host's `HttpClient`, while tests and tools can supply a fake. Remote
delete additionally needs the publisher to implement `AtprotoDeleter`; the
default XRPC adapter does, so no extra host argument is needed unless a host
replaces the publisher.

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
    // Optional: enables the inbound importer and atproto.sync handler.
    atprotoEventSource: $relaySource,
    // Optional: defaults to PdoAtprotoInboundRepository($database).
    atprotoInbound: null,
    // Optional: defaults to AllowAllAtprotoContent.
    atprotoModeration: null,
    // Optional: turns blob CIDs into host-served URLs.
    atprotoBlobs: null,
);
```

`$relaySource` is a host implementation of `AtprotoEventSource`. Without one,
`$app->atprotoSync` and the `atproto.sync` handler are `null`, so a host that
only publishes is unchanged. `Application::inMemory()` accepts the same
optional arguments for tests,
tools and the demo. Without `atprotoAccounts` the service is not configured;
`social/atproto.publish` can be on while the route still answers `503
atproto_not_configured`, which makes a half-finished deployment visible rather
than pretending to publish.

The host must create `AtprotoPublicationSchema` (see [database](database.md))
and grant the PDS account permission to write `app.bsky.feed.post` records.
The core never reads or writes the host's credential store.

For remote graph follows, create `AtprotoFollowSchema` as well and grant the
connected account permission to write and delete `app.bsky.graph.follow`
records. Follow operations are available only when `social/atproto.publish` is
enabled and the host supplies a connected account, publisher, deleter, record
resolver and durable follow repository. A failed PDS request leaves a pending
row that a repeated request can resume with the same rkey.

## OAuth session refresh

A host that stores an OAuth session can let the core perform the refresh
exchange without moving the session into a core table:

```php
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionStore;
use AlohaSocial\Core\Protocol\Atproto\Es256DpopProofProvider;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoSessionRefresher;
use AlohaSocial\Core\Service\Atproto\AtprotoSessionService;

$sessions = new AtprotoSessionService(
    $hostSessionStore, // implements AtprotoSessionStore
    new XrpcAtprotoSessionRefresher(
        $httpClient,
        new Es256DpopProofProvider($dpopPrivateKeyPem),
        $clock,
    ),
    $clock,
);

$session = $sessions->current($localUserId);
```

`current()` returns `null` for an unlinked user, returns a valid session
without a request, and otherwise refreshes inside the 60-second leeway before
saving the rotated access/refresh pair. The host must serialize refreshes for
one session: AT Protocol refresh tokens are single-use, so two concurrent
refreshes can consume the same token. Save the rotated session before using the
new access token. If persisting fails, discard the old session and require a
new authorization rather than retrying with the consumed token.

The refresh exchange sends `POST` to the session's `tokenEndpoint` with
`grant_type=refresh_token`, the old refresh token and the client id, encoded as
`application/x-www-form-urlencoded`. Every request has a fresh ES256 DPoP proof
with a unique `jti`; the first `401 use_dpop_nonce` is retried once with the
server's `DPoP-Nonce`. A successful response must contain a DPoP token type, a
rotated refresh token, the `atproto` scope, the same `sub` DID and a positive
`expires_in`. No token or authorization-server body appears in an exception.

`AtprotoSessionRefreshException` classifies failures for the host:

| `errorCode` | Retryable | Meaning |
| --- | --- | --- |
| `transport_error` | yes | The authorization server could not be reached |
| `invalid_response` | yes | The response was malformed, changed the DID or omitted a required field |
| `dpop_nonce_missing` | no | A DPoP challenge or success response had no usable nonce |
| OAuth error code, e.g. `invalid_grant` | only `429`/`5xx` | The server refused the refresh; `invalid_grant` requires re-authorization |

`XrpcAtprotoPublisher` takes an optional `DpopResourceProofProvider`. Without
one it keeps sending the host's bearer/app-password token, so an existing host
is unchanged. When the host passes its `Es256DpopProofProvider`, every record
write is a DPoP resource request: `Authorization: DPoP <access token>`, a fresh
proof over the request method and URL, and the RFC 9449 `ath` binding to that
token. Exactly one `400`/`401 use_dpop_nonce` challenge is retried with the
server's `DPoP-Nonce`; a second challenge returns the PDS status and a missing
nonce header is not retried. No proof, nonce or token appears in an exception
or a log line. The host still serializes refreshes per session, because AT
Protocol refresh tokens are single-use.

No `social` config key and no route is added. The session store is a host port
because tokens are credentials; the core deliberately ships no
`social_atproto_session` table or migration.

## Authorization-code flow

The OAuth client is a host-called protocol operation; there is no core route
and no core flow or session table. The host must keep the returned request by
its `state`, compare the callback state, and keep the PKCE verifier and DPoP
nonce out of the browser.

```php
use AlohaSocial\Core\Protocol\Atproto\AtprotoOAuthMetadataResolver;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoOAuthClient;

$server = (new AtprotoOAuthMetadataResolver($httpClient))->resolve($pdsUrl);
$request = $oauthClient->begin($server, $did, $pdsUrl, $handle);
// Redirect the browser to $request->authorizationUrl after saving $request
// under $request->state. On the callback, verify state and pass the `iss`.
$session = $oauthClient->complete($request, $code, $iss);
// Save $session in the host's credential store, then link the DID locally.
```

`AtprotoOAuthMetadataResolver` requires exactly one authorization server and
rejects metadata that does not bind to the discovered issuer or does not
advertise the AT Protocol requirements. `begin()` requires a proof provider
that implements `DpopKeyThumbprint`; a refresh-only provider without it fails
with `oauth_client_not_configured` instead of sending a PAR without the key
binding. `complete()` requires the callback `iss` to equal the discovered
issuer and the token `sub` to equal the requested DID, so a response for
another account or server cannot be stored. `revoke()` uses the refresh token
and the advertised revocation endpoint; a server without that endpoint is
`oauth_unsupported` rather than a silent success.

`AtprotoOAuthException` is the stable failure type: it never includes tokens,
codes or the response body. `transport_error` and `invalid_response` are
retryable; OAuth refusals expose a safe `errorCode` and are retryable only for
`429`/`5xx`; `dpop_nonce_missing` and `oauth_client_not_configured` are not.

## Flow

1. `PostService::create()` stores the post, then calls
   `AtprotoPublishService::schedule()`. Scheduling is a no-op when the feature
   is disabled, the assembly is incomplete, the post is not public, it has
   attachments, its text is too long, it replies to a parent that was never
   published to AT Protocol, or the author has no linked account.
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
6. `PostService::delete()` soft-deletes the post, then calls
   `AtprotoPublishService::scheduleDelete()`. If a publication row exists, a
   durable `atproto.delete` job is queued with `{user_id, post_id}`. The worker
   resolves the account, calls `deleteRecord` with the stored rkey, and removes
   the local publication row only after the PDS accepts the delete. A PDS or
   transport failure leaves the row and is retried with the normal backoff; a
   404 is treated as already deleted, so a lost success does not stay stuck.
   A post deleted before its publish job ran has no publication row and queues
   no delete; the pending publish job sees the deleted post and skips.

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

### Reading the publication state

`GET /api/v1/posts/{id}/atproto` returns the stored state for the caller's own
post without contacting the PDS:

```json
{"publication": null}
```

before the first publication, and the object shown above afterwards. It reads
the local idempotency row, so it still answers after `social/atproto.publish`
is switched off, and it never repairs or retries a publication. A missing,
deleted or foreign post is `404 not_found`; an anonymous request is `401
authentication_required`. The route is rate-limited at `120/60`. See
[two protocols, one model](dual-protocol.md) for how the local post, the
ActivityPub object and the PDS record relate.

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
unique. A local post deletion queues the matching remote delete and removes the
row only after the PDS accepted it. If the feature is disabled, the account is
missing or the host supplied no `AtprotoDeleter`, the row is retained and the
job is logged as skipped, so recovery is explicit rather than a silent local
removal. `AtprotoPublicationRepository::removeForPost()` remains available for
a host that deliberately wants to drop a local mapping, but removing only the
row does not change the PDS. There is no new table or migration for remote
delete.

## Testing

`AtprotoPublishTest` drives the complete local path through the real HTTP
kernel: post creation, queued job, fake XRPC adapter, publication row and JSON.
It covers automatic and explicit publication, repeat idempotency, an unsupported
post, a disabled feature, auth, an unknown post, a missing account, content
warning labels and a PDS failure that leaves no row. Remote-delete coverage
adds deleteRecord, publication removal, retry after a PDS failure, a 404 as
idempotent success and the no-publication/no-double-job cases.
`AtprotoPublishServiceTest` covers the schedule skip rules for attachments,
non-public posts and long text plus disabled/not-configured deletion;
`XrpcAtprotoPublisherTest` checks the exact XRPC requests, the DPoP resource
request with its `ath` binding, the single nonce retry and malformed/refused
responses; `TidTest` and the repository contract cover the protocol and
persistence primitives. `AtprotoFacetsTest` covers byte offsets with umlauts and
emoji, the tag/mention/link boundaries, overlap suppression and a handle that
resolves or is refused; `AtprotoReplyBuilderTest` covers root inheritance, the
CID lookup, an unreadable parent and an unreadable root;
`XrpcAtprotoIdentityResolverTest` and `XrpcAtprotoRecordResolverTest` cover the
XRPC adapters and their failure answers. `XrpcAtprotoBlobUploaderTest` covers
the upload request, the DPoP variant, a refused upload, a transport failure and
a success without a blob reference; `AtprotoEmbedTest` covers 1–4 images, a
video, mixed media, every refusal above and the aspect-ratio pass-through.
`AtprotoProfileRecordTest` covers the `self` record and its bounds;
`AtprotoProfileServiceTest` covers the avatar upload, both refusals without a
request, the delete and the feature switch; `XrpcAtprotoTimelineReaderTest`
covers paging, limits, an empty feed and the two failure reasons.
`AtprotoInteractionRecordTest` covers both interaction records and the strong
reference validation; `AtprotoInteractionServiceTest` covers like/repost,
the undo on the same record key, the feature switch, a missing account, a
missing publication, an unresolvable subject and the collection on the wire. `AtprotoOAuthSessionTest` covers session validation and
the expiry leeway; `XrpcAtprotoSessionRefresherTest` covers the form request,
rotation, DPoP nonce retry, terminal OAuth errors and malformed responses;
`Es256DpopProofProviderTest` verifies the JWS signature, the RFC 7638
thumbprint, unique `jti` and the `ath`-bound resource proof; `AtprotoSessionServiceTest` covers refresh-on-expiry,
persistence and a failed refresh that leaves the old session untouched.
`AtprotoOAuthMetadataResolverTest` covers discovery, issuer binding and invalid
metadata; `XrpcAtprotoOAuthClientTest` covers the PAR form, PKCE/`dpop_jkt`,
nonce retries, code exchange, issuer/DID rejection and revocation against fake
HTTP and DPoP adapters.

## Remaining external work

The full AT Protocol stack is still host/app work:

- account-linking UI and browser callback, the short-lived flow store, the
  credential store and per-account PDS selection (the protocol client and
  authorization-server discovery are implemented locally);
- the inbound event transport (WebSocket/HTTP relay or Jetstream consumption,
  reconnect/backoff and normalization into `AtprotoEvent` pages); the importer,
  cursor, tombstones, AppView reconciliation state and cached feed are local;
- a full repository client (CAR/MST writes, arbitrary record listing/update);
  canonical CID/DAG-CBOR/CAR/MST reading and signed commit round-trips are
  implemented in the separate native-PDS foundation, while AppView timeline
  reads are implemented as a port;
- video transcoding and image processing for AT Protocol (the core uploads
  bytes the caller already prepared);
- link cards and richer mention/facet targets;
- migrating the existing AT Protocol implementation out of the Nextcloud app.

Remote delete for records published by this core is implemented locally, and
the OAuth token-refresh exchange, authorization-code/PAR flow and revocation
are implemented locally. Deleting records created outside this core, the
account-linking UI/callback, the credential store and full account/AppView
extraction remain external.

The remaining protocol follow-ups — link cards, richer media and the
relay/AppView consumer transport — are listed as Later in the
[roadmap](../roadmap.md). The rest is tracked there as the compatibility
remainder (M6), not as part of this publishing foundation.

The optional **native PDS** is a separate milestone, not a replacement for
this publishing path. Its accepted architecture and security gates are in
[ADR 0008](../adr/0008-native-pds.md); the working identity/repository store,
DID/PLC lifecycle and XRPC routes are documented in
[Native AT Protocol PDS](atproto-repository.md). It is disabled by default;
blob authorization, CAR import/blocks and the self-hosted firehose WebSocket
are implemented.
