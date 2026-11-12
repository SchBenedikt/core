# Error codes

Every failure the core answers has this shape:

```json
{"error": {"code": "validation_failed", "message": "…", "fields": {"content": "…"}}}
```

`code` is stable and meant for clients. `message` is human-readable and may
change. `fields` appears only when an error belongs to specific input.

| Code | Status | When |
| --- | --- | --- |
| `invalid_json` | 400 | The body is not valid JSON or not an object |
| `invalid_multipart` | 400 | The upload is not valid `multipart/form-data` (missing boundary, malformed part, repeated field) |
| `invalid_media_variant` | 400 | `variant` is neither absent, `original` nor `thumbnail` |
| `payload_too_large` | 413 | The multipart request is larger than the file limit plus framing |
| `invalid_cursor` | 400 | `before` is not a canonical timestamp |
| `invalid_resource` | 400 | WebFinger `resource` is missing or not a usable `acct:` URI |
| `authentication_required` | 401 | The route needs a user and the request has none; carries `WWW-Authenticate: Bearer` |
| `forbidden` | 403 | The user is known but may not do this (deleting somebody else's post) |
| `insufficient_scope` | 403 | A Mastodon-compatible route requires a token scope the caller does not hold |
| `not_found` | 404 | Missing — or private and therefore indistinguishable from missing; also an unknown Mastodon status cursor or local account |
| `mastodon_api_disabled` | 404 | `social/mastodon.api` is off; every compatibility route answers this before auth |
| `unknown_actor` | 404 | An inbox request signed with a key id that does not resolve to an actor |
| `unknown_object` | 404 | An inbox activity does not address an actor on this instance |
| `actor_mismatch` | 403 | A valid inbox signature whose activity claims another actor, a created object belongs to another actor, or a Delete targets another actor's row |
| `blocked_actor` | 403 | The host's blocked-actor policy refuses the signing actor |
| `not_followed` | 403 | Inbound `Create`/`Delete`/`Announce` from an actor no local account follows |
| `follow_not_accepted` | 403 | The host's `FollowPolicy` refuses an inbound Follow for that local account |
| `signature_required` | 401 | An inbox request without a `Signature` header |
| `invalid_signature` | 401 | Malformed header, missing signed header, digest mismatch, wrong key or unsupported algorithm |
| `signature_expired` | 401 | The signed `Date` is outside the 300-second window |
| `invalid_activity` | 400 | An inbox activity without `id`, `type` or `actor`, or with a malformed object |
| `unsupported_activity` | 400 | An inbox activity type the core does not apply |
| `replayed_activity` | 400 | An activity id that was already accepted |
| `inbox_not_configured` | 503 | No actor resolver, HTTP client or signing key configured |
| `media_not_configured` | 503 | No `BlobStore` is configured for the media routes |
| `media_storage_unavailable` | 503 | A validated upload could not be written to the blob store |
| `delivery_failed` | 502 | A peer inbox answered non-2xx or could not be reached while delivering a local Follow or Undo. A Follow is rolled back; an Undo keeps the edge so the request can be retried |
| `federation_not_configured` | 503 | A local follow write has no HTTP client or signing key to deliver the Follow/Undo |
| `thread_not_configured` | 503 | The thread route is present but no bounded HTTP client/service was assembled |
| `thread_fetch_disabled` | 503 | `social/thread.fetch` is off, so no request is made to the peer |
| `thread_fetch_failed` | 502 | The target remote object could not be fetched, was too large/timed out, or was not a usable ActivityPub object |
| `atproto_publish_disabled` | 503 | `social/atproto.publish` is off, so no PDS request is made |
| `atproto_not_configured` | 503 | The AT Protocol route is present but no account resolver or publisher was assembled |
| `atproto_account_not_connected` | 409 | The local user has no AT Protocol account linked by the host |
| `atproto_post_unsupported` | 422 | The post cannot be represented (non-public, attachments or too long); `error.fields.reason` is stable |
| `atproto_publish_failed` | 502 | The PDS refused a record write/delete or could not be reached; a failed publish writes no row, a failed delete keeps the existing row for retry |
| `actor_not_found` | 404 | A handle that is not an account on this instance, or a WebFinger host that is not this instance (actor document, followers/following collections, outbox, WebFinger) |
| `actor_not_configured` | 503 | No signing key or no local actor directory configured (actor document, followers/following collections, outbox, WebFinger) |
| `method_not_allowed` | 405 | The path exists for another method; carries `Allow` |
| `validation_failed` | 422 | Input violates the contract; `fields` names each problem |
| `unsupported_parameter` | 422 | A Mastodon write asks for `direct` visibility or `in_reply_to_id`, which this local slice does not support |
| `internal_error` | 500 | Unexpected; the message is hidden unless debug mode is on |
| `instance_not_configured` | 503 | `social/instance.url` is missing or invalid, so no actor or federation URL can be built |

## Mastodon compatibility failures

A disabled compatibility surface is `404 mastodon_api_disabled`, including for
authenticated writes. A missing token is still `401
authentication_required`; a valid token without the route's scope is `403
insufficient_scope`. An unknown `max_id`/`since_id`/`min_id`, a missing or
deleted status, a private status for anyone but its author, and an unknown
local account are all `404 not_found`. `direct` visibility and
`in_reply_to_id` are `422 unsupported_parameter`; other input problems use
`422 validation_failed` with `error.fields`. Media upload reuses the core media
codes: `400 invalid_multipart`, `413 payload_too_large`, `422
validation_failed`, `503 media_not_configured` and `503
media_storage_unavailable`; `write:media` is enforced like every other declared
scope. A notification cursor must belong to the signed-in recipient; an
unknown or foreign cursor is `404 not_found`. An assembly without the needed
repository answers `503 favourites_not_configured` or `503
notifications_not_configured`, and an assembly without local relationships
answers `503 follows_not_configured`. See [Mastodon-compatible API](../guides/mastodon-api.md).

## Remote thread failures

The thread route has three HTTP codes above. Failures while walking an ancestor
or reading the direct-reply collection are logged with stable reasons and do
not fail the request: `thread_invalid_url`, `thread_dns_failed`,
`thread_ssrf_blocked`, `thread_fetch_failed`, `thread_timeout`,
`thread_http_error`, `thread_too_many_redirects`, `thread_redirect_invalid`,
`thread_too_large`, `thread_invalid_document`, `thread_disabled` and
`thread_store_failed` (the cache write failed but the fetched response is
still returned). The target failure is logged with the same reason before the
handler answers `502`. See [remote threads](../guides/remote-threads.md).

## AT Protocol job failures

AT Protocol job failures are logged by the existing worker. A disabled or
unconfigured feature, a missing account and an unsupported post finish the job
without retrying; a PDS transport/refusal failure is retried with the normal
queue backoff and the same deterministic record key. A retry that follows a
lost success returns the stored row instead of writing a duplicate. Delete
jobs use the stored rkey; a 404 is treated as already deleted, and a failed
delete keeps the publication row so the retry can finish. A delete without a
publication is a no-op and a second local delete creates no second job. See
[AT Protocol publishing](../guides/atproto.md).

## AT Protocol OAuth refresh failures

OAuth refresh is a host-called protocol operation, not an HTTP route, so it has
no wire error code. `AtprotoSessionRefreshException` exposes a safe
`errorCode`, the authorization-server `status` (or `0` for transport) and a
`retryable` flag: `transport_error` and `invalid_response` are retryable, a
missing DPoP nonce is `dpop_nonce_missing` and is not, and an OAuth refusal
keeps the server's code (for example `invalid_grant`); only `429` and `5xx`
are retryable. Messages never contain a token, a refresh token or the response
body. See [AT Protocol publishing](../guides/atproto.md#oauth-session-refresh).

## Remote media failures

Remote attachment failures do not have an HTTP route of their own and do not
change the response to the delivering peer's inbox. They are logged with a
stable `reason` and are documented in
[media](../guides/media.md#failure-reasons) (`remote_media_ssrf_blocked`,
`remote_media_too_large`, `remote_media_timeout`, `remote_media_invalid_image`,
and so on). The post remains available without the affected attachment. Do
not invent a new HTTP error code for these events.

## Rules

- Never add a code without adding it to this table.
- Never reuse a code for a different meaning; clients switch on it.
- A 404 for a private resource is deliberate. Do not "improve" it to 403.
- `fields` keys are the JSON field names (`content`, `content_warning`,
  `visibility`, `actor`, `inbox`, `file`, `alt_text`, `attachment_ids`), never
  internal column names.
- The kernel owns the shape; a handler that builds an error object by hand is a
  bug waiting to happen.
