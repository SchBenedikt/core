# AT Protocol publishing

The core owns a deliberately small, public, text-only AT Protocol publishing
foundation. It owns deterministic record writes, the matching remote record
delete for mirrored posts, the OAuth refresh exchange for a host-owned session
and the authorization-code/PAR/revocation protocol client. It does not own the
account-linking UI/callback, the credential store, PDS selection, the AppView or
a full repository client; those remain in the host or in a future standalone
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
truncated. There is no partial record, blob upload, image embed, edit,
thread/reply, facet/link detection, AppView read, firehose consumer or CAR/MST
repository implementation in this slice. Remote delete is implemented for
records the core itself published; deleting arbitrary PDS records is not.

OAuth session refresh and the authorization-code/PAR/revocation protocol are
implemented. The browser redirect, the short-lived flow store keyed by state,
the linked-account UI, the account/token store and PDS selection stay in the
host. The core's `AtprotoAccountResolver` is intentionally a port, not a
credential table, and it creates no linked-account or OAuth-flow row. A host
that has no resolver still serves the publish route, but it answers `503
atproto_not_configured`.

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

The refresh flow does not change `XrpcAtprotoPublisher`: that adapter still
uses the host's bearer/app-password token for record writes. OAuth access
tokens use the same token endpoint refresh but require a DPoP resource request,
which remains part of the external account-linking/client work.

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
`XrpcAtprotoPublisherTest` checks the exact XRPC requests and malformed/refused
responses; `TidTest` and the repository contract cover the protocol and
persistence primitives. `AtprotoOAuthSessionTest` covers session validation and
the expiry leeway; `XrpcAtprotoSessionRefresherTest` covers the form request,
rotation, DPoP nonce retry, terminal OAuth errors and malformed responses;
`Es256DpopProofProviderTest` verifies the JWS signature, the RFC 7638
thumbprint and unique `jti`; `AtprotoSessionServiceTest` covers refresh-on-expiry,
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
- a full repository client (CAR/MST, arbitrary record listing/update),
  AppView reads, firehose consumption and blob upload;
- media embeds and image processing for AT Protocol;
- reply/thread mapping, facets, mentions and link cards;
- migrating the existing AT Protocol implementation out of the Nextcloud app.

Remote delete for records published by this core is implemented locally, and
the OAuth token-refresh exchange, authorization-code/PAR flow and revocation
are implemented locally. Deleting records created outside this core, the
account-linking UI/callback, the credential store and full account/AppView
extraction remain external.

The remaining protocol follow-ups after account linking — facets, mentions,
link cards, media embeds and reply/thread mapping — are listed as Later in the
[roadmap](../roadmap.md). The rest is tracked there as the compatibility
remainder (M6), not as part of this publishing foundation.
