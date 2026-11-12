# Mastodon-compatible API

The core ships a small, optional Mastodon-compatible read/write surface over
the **same** posts and repositories as `/api/v1`. It is additive: enabling it
does not change, replace or shadow `/api/v1`, and it is **off by default**.

This is not a second status store. A Mastodon status is a core post with an
API-shaped projection, so deleting a status through either API soft-deletes
the same row and a write is still validated, stored and delivered by
`PostService`.

## Status and scope

Locally implemented (core):

- `GET /instance`
- `GET /timelines/public`
- `GET /statuses/{id}`
- `POST /statuses`
- `DELETE /statuses/{id}`
- `GET /accounts/{id}/statuses`
- `GET /accounts/verify_credentials`

The local subset covers public/unlisted/followers-only text posts and local
media attachments that already exist. It deliberately does **not** implement
remote timelines, favourites, boosts, notifications, streaming, polls,
scheduled posts, filters, followed tags, lists, OAuth authorization/token
endpoints, media upload, search, admin APIs or the full account/profile
surface. Those remain external work; see the section at the end.

## Configuration and prefix

| Key | Default | Meaning |
| --- | --- | --- |
| `social/mastodon.api` | `false` | Enables the compatibility handlers and gate. |
| `social/mastodon.prefix` | `/api/mastodon/v1` | Path prefix for all seven routes. |

`mastodon.prefix` must be a non-root path without a trailing slash, query,
fragment, template, empty/parent segment or unsupported characters. It must
not be `/api/v1` or a child of it, and it must not overlap `/healthz`,
`/inbox`, `/@` or `/.well-known`. A bad prefix fails at boot with a
`ConfigurationException` instead of producing a collision.

With the switch off, **every** compatibility route answers `404
mastodon_api_disabled` — including authenticated writes. The gate runs before
authentication and scope checks, so a disabled feature does not disclose a
different result to a caller who supplies a token. The routes stay in the
manifest and OpenAPI export so a host can serve a stable contract; enabling the
feature is a configuration change, not a route change.

The custom prefix is used by both `Application::assemble()` and
`Routes::manifest($prefix)`. `bin/export-routes` and `bin/export-openapi` use
the default prefix unless a caller passes one.

## Authentication and scopes

The host authenticates the token and puts the local user id on
`Request::$userId` and the token scopes on `Request::$scopes`. The core never
issues tokens and does not know a Mastodon OAuth app or client secret.

| Route | Auth | Required scope | Rate limit |
| --- | --- | --- | --- |
| `GET {prefix}/instance` | no | — | 120 / 60 s |
| `GET {prefix}/timelines/public` | no | — | 60 / 60 s |
| `GET {prefix}/statuses/{id}` | no | — | 120 / 60 s |
| `POST {prefix}/statuses` | yes | `write:statuses` | 30 / 900 s |
| `DELETE {prefix}/statuses/{id}` | yes | `write:statuses` | 30 / 900 s |
| `GET {prefix}/accounts/{id}/statuses` | no | — | 60 / 60 s |
| `GET {prefix}/accounts/verify_credentials` | yes | `read:accounts` | 60 / 60 s |

A parent scope grants its children (`read` grants `read:accounts`); a granular
scope does not grant its parent. `*` grants everything. A token with too few
scopes gets `403 insufficient_scope`. The host still owns bearer parsing, token
expiry/revocation and the actual rate limiter; the route manifest only declares
the budget, exactly like the rest of the core API.

The local-account directory, when supplied, is checked for
`verify_credentials`, status creation and account statuses. Without a
directory the core cannot distinguish local from remote account ids; a host
that enables this API should always pass one.

## Reading statuses

`GET /timelines/public` and `GET /accounts/{id}/statuses` return a JSON array
of status objects. `GET /statuses/{id}` returns one object. The mapping is:

- `id` is the core post id; it is opaque to clients.
- `account.id`/`acct`/`username` is the local user id. A full profile is
  host-owned; display name, avatar and counts are minimal defaults.
- `visibility`: `public`, `unlisted`, `private` (core followers-only) or
  `direct` (not writable locally).
- `content` is escaped text in a single `<p>`; `spoiler_text` is the content
  warning. A content warning implies `sensitive: true`.
- `media_attachments` projects the existing attachments, their alt text and
  original/thumbnail URLs. No new blob or media row is created by this API.
- `uri`/`url` point at the core post URL; `created_at` is the stored publish
  time.

A non-public status is readable only by its author. For everyone else it is
`404 not_found`, like the core API; the status route does not leak existence.
Account statuses are public/unlisted only, including when the account asks for
its own list.

## Writing and deleting

`POST /statuses` accepts `application/json` or
`application/x-www-form-urlencoded`:

```json
{
  "status": "hello",
  "visibility": "public",
  "spoiler_text": "optional",
  "sensitive": false,
  "media_ids": ["existing-attachment-id"]
}
```

`private` maps to followers-only. `direct` is refused with `422
unsupported_parameter` because the core has no recipient model yet. Empty
content, over-limit text, more than four attachments and an attachment not
owned by the caller are refused by `PostService` with `422
validation_failed` and `error.fields`. `in_reply_to_id` is also refused with
`422 unsupported_parameter` in this slice.

`DELETE /statuses/{id}` soft-deletes the caller's status and returns `204`.
Another account's status, a missing status or an already deleted status is
`404 not_found`; the ownership check happens in `PostService`, not in a
handler copy.

### Idempotency

Send `Idempotency-Key: <client-key>` on `POST /statuses`. The core derives a
deterministic post id from the local user id and the key and passes it to
`PostService`. The first request inserts; a retry hits the unique post id,
re-reads that row and returns the same status instead of inserting a second
one. There is no extra idempotency table and no cleanup job. The key is
scoped to the user, so two users may use the same client key.

Repeating a key with different content returns the original status, as an
idempotency key should. A failed validation or storage attempt writes no row
and no delivery job; a later retry may succeed. Keys are meant to be unique per
logical write, not per request body.

## Pagination

`limit` defaults to 20 and is clamped to 1–40. A value outside the range is
first accepted as an integer and then clamped; a non-integer falls back to the
default.

- `max_id` returns statuses older than that status, using the post's publish
  time and id as a tie-break so several posts in the same second cannot repeat
  or disappear.
- `since_id` returns statuses newer than that status.
- `min_id` is accepted as the same newer-than cursor when `since_id` is
  absent.
- `max_id` cannot be combined with `since_id`/`min_id`; that is `422
  validation_failed`.
- An unknown cursor id is `404 not_found`.

A non-empty page carries `Link: <…?limit=…&max_id=…>; rel="next"`. Clients can
follow it until the page is empty. This uses the existing post repository's
id-aware cursor; the API does not keep a per-client timeline.

## Errors

The normal core JSON error shape applies. The compatibility-specific codes are:

| Code | Status | When |
| --- | --- | --- |
| `mastodon_api_disabled` | 404 | `social/mastodon.api` is off |
| `insufficient_scope` | 403 | The token lacks the route's required scope |
| `unsupported_parameter` | 422 | `direct` visibility or `in_reply_to_id` in this slice |
| `validation_failed` | 422 | Missing/invalid status fields or media ids; see `error.fields` |
| `not_found` | 404 | Missing/private status, unknown cursor or unknown local account |

Authentication failures use the existing `401 authentication_required`; the
host's limiter should use `429` with its own retry metadata. The core does not
invent a second error shape for the compatibility surface.

## Database and cleanup

No new table is needed. Statuses live in `social_post` /
`social_post_attachment`; idempotency uses the primary key and a deterministic
id. There is no migration for this slice. Deleting a status follows the normal
soft-delete path; attachment rows and blobs are governed by the existing media
cleanup policy, not duplicated here.

## OpenAPI and tests

The seven routes are in the manifest with auth, scope and rate-limit metadata.
`bin/export-openapi` includes them with the `mastodon` tag and
`x-required-scopes`. `MastodonApiTest` drives the real kernel through instance,
timeline, create (JSON and form), read, account statuses, verify, delete,
scopes, disabled, custom prefix, cursor, idempotency and invalid-media cases.
`MastodonCompatibilityTest` covers prefix validation, scope matching and route
metadata. The post repository contract now covers the id-aware cursor and
duplicate-id behavior used by the API.

## What remains external

Turning this into a complete Mastodon server is intentionally out of scope:

- OAuth authorization, token issuance/refresh/revocation and app registration
  belong to the host or a dedicated identity service.
- Remote statuses, public/remote timelines, favourites, boosts, bookmarks,
  notifications, streaming and search need remote data the core does not
  fetch for this surface.
- Media upload, polls, scheduled posts, filters, lists and the full account
  profile/relationship APIs are separate features.
- Follow/follower endpoints and moderation/admin APIs are not part of this
  slice.
- The host must map bearer tokens to `userId`/`scopes`, enforce the declared
  rate limits and decide whether to expose the custom prefix publicly.

These remain tracked in the roadmap as host/integration work; the local
routes, mapping, validation, pagination, idempotency and error behavior above
are complete and tested.
