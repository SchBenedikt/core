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
- `GET /statuses/{id}/context`
- `GET /polls/{id}`
- `POST /polls/{id}/votes`
- `POST /statuses`
- `POST /statuses/{id}/favourite`
- `POST /statuses/{id}/unfavourite`
- `POST /statuses/{id}/bookmark`
- `POST /statuses/{id}/unbookmark`
- `GET /bookmarks`
- `POST /statuses/{id}/reblog`
- `POST /statuses/{id}/unreblog`
- `GET /accounts/relationships`
- `POST /accounts/{id}/follow`
- `POST /accounts/{id}/unfollow`
- `POST /accounts/{id}/mute`
- `POST /accounts/{id}/unmute`
- `GET /mutes`
- `GET /lists`
- `POST /lists`
- `GET /lists/{id}`
- `PUT /lists/{id}`
- `DELETE /lists/{id}`
- `GET /lists/{id}/accounts`
- `POST /lists/{id}/accounts`
- `DELETE /lists/{id}/accounts`
- `GET /timelines/list/{list_id}`
- `POST /media`
- `PUT /media/{id}`
- `DELETE /media/{id}`
- `DELETE /statuses/{id}`
- `GET /accounts/{id}/statuses` (with optional viewer-specific visibility)
- `GET /accounts/{id}`
- `GET /accounts/{id}/followers`
- `GET /accounts/{id}/following`
- `GET /accounts/verify_credentials`
- `GET /notifications`
- `POST /notifications/{id}/dismiss`
- `POST /notifications/clear`
- `GET /notifications/unread_count`
- `GET /search`

The local subset covers public/unlisted/followers-only text posts, local poll
creation on an own status, local favourite/unfavourite, private bookmark and
mute bookkeeping, private list bookkeeping with list timelines and the
exclusive home filter, boost/reblog bookkeeping, reply context, local
follow/unfollow and relationship bookkeeping,
local notifications with dismiss/unread state, media upload, metadata
update/delete and local media attachments. It deliberately
does **not** implement remote timelines, following a remote actor through this
compatibility prefix, remote favourite federation (outbound `Like`/`Undo` or
remote favourite counts), remote boost federation, remote notifications,
streaming, voting on or fetching remote polls, scheduled posts,
filters, followed tags, OAuth authorization/token endpoints, remote
search, remote profile fetching, admin APIs or the full account management
surface. Lists are limited to known local account ids; remote list membership
is external.
Local search over local accounts and statuses is implemented below; remote
search and hashtag search are external. The locally implementable remainder —
hashtags and hashtag search, filters, followed tags, scheduled posts, follow
requests/locked accounts and the wider account-management surface — is a
product follow-up listed as Later in the [roadmap](../roadmap.md); everything
else needs a host or remote data. See the section at the end.

## Configuration and prefix

| Key | Default | Meaning |
| --- | --- | --- |
| `social/mastodon.api` | `false` | Enables the compatibility handlers and gate. |
| `social/mastodon.prefix` | `/api/mastodon/v1` | Path prefix for all forty-three routes. |

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
| `POST {prefix}/media` | yes | `write:media` | 20 / 900 s |
| `PUT {prefix}/media/{id}` | yes | `write:media` | 30 / 900 s |
| `DELETE {prefix}/media/{id}` | yes | `write:media` | 30 / 900 s |
| `GET {prefix}/timelines/public` | no | — | 60 / 60 s |
| `GET {prefix}/statuses/{id}` | no | — | 120 / 60 s |
| `GET {prefix}/statuses/{id}/context` | no | — | 60 / 60 s |
| `GET {prefix}/polls/{id}` | no | — | 120 / 60 s |
| `POST {prefix}/polls/{id}/votes` | yes | `write:statuses` | 30 / 900 s |
| `POST {prefix}/statuses` | yes | `write:statuses` | 30 / 900 s |
| `DELETE {prefix}/statuses/{id}` | yes | `write:statuses` | 30 / 900 s |
| `POST {prefix}/statuses/{id}/favourite` | yes | `write:favourites` | 30 / 900 s |
| `POST {prefix}/statuses/{id}/unfavourite` | yes | `write:favourites` | 30 / 900 s |
| `POST {prefix}/statuses/{id}/bookmark` | yes | `write:bookmarks` | 30 / 900 s |
| `POST {prefix}/statuses/{id}/unbookmark` | yes | `write:bookmarks` | 30 / 900 s |
| `GET {prefix}/bookmarks` | yes | `read:bookmarks` | 60 / 60 s |
| `POST {prefix}/statuses/{id}/reblog` | yes | `write:statuses` | 30 / 900 s |
| `POST {prefix}/statuses/{id}/unreblog` | yes | `write:statuses` | 30 / 900 s |
| `GET {prefix}/accounts/relationships` | yes | `read:follows` | 60 / 60 s |
| `POST {prefix}/accounts/{id}/follow` | yes | `write:follows` | 30 / 900 s |
| `POST {prefix}/accounts/{id}/unfollow` | yes | `write:follows` | 30 / 900 s |
| `POST {prefix}/accounts/{id}/mute` | yes | `write:mutes` | 30 / 900 s |
| `POST {prefix}/accounts/{id}/unmute` | yes | `write:mutes` | 30 / 900 s |
| `GET {prefix}/mutes` | yes | `read:mutes` | 60 / 60 s |
| `GET {prefix}/lists` | yes | `read:lists` | 60 / 60 s |
| `POST {prefix}/lists` | yes | `write:lists` | 30 / 900 s |
| `GET {prefix}/lists/{id}` | yes | `read:lists` | 60 / 60 s |
| `PUT {prefix}/lists/{id}` | yes | `write:lists` | 30 / 900 s |
| `DELETE {prefix}/lists/{id}` | yes | `write:lists` | 30 / 900 s |
| `GET {prefix}/lists/{id}/accounts` | yes | `read:lists` | 60 / 60 s |
| `POST {prefix}/lists/{id}/accounts` | yes | `write:lists` | 30 / 900 s |
| `DELETE {prefix}/lists/{id}/accounts` | yes | `write:lists` | 30 / 900 s |
| `GET {prefix}/timelines/list/{list_id}` | yes | `read:lists` | 60 / 60 s |
| `GET {prefix}/accounts/{id}/statuses` | no | — | 60 / 60 s |
| `GET {prefix}/accounts/{id}` | no | — | 120 / 60 s |
| `GET {prefix}/accounts/{id}/followers` | no | — | 60 / 60 s |
| `GET {prefix}/accounts/{id}/following` | no | — | 60 / 60 s |
| `GET {prefix}/accounts/verify_credentials` | yes | `read:accounts` | 60 / 60 s |
| `GET {prefix}/notifications` | yes | `read:notifications` | 60 / 60 s |
| `GET {prefix}/notifications/unread_count` | yes | `read:notifications` | 60 / 60 s |
| `POST {prefix}/notifications/{id}/dismiss` | yes | `write:notifications` | 30 / 900 s |
| `POST {prefix}/notifications/clear` | yes | `write:notifications` | 30 / 900 s |
| `GET {prefix}/search` | yes | `read:search` | 60 / 60 s |

A parent scope grants its children (`read` grants `read:accounts`,
`read:follows`, `read:bookmarks`, `read:mutes`, `read:lists`,
`read:notifications` and `read:search`, `write` grants `write:favourites`,
`write:bookmarks`, `write:mutes`, `write:lists`, `write:follows`,
`write:media` and `write:notifications`); a granular scope does
not grant its parent.
`*` grants everything. A token with too few scopes gets `403
insufficient_scope`. The host still owns bearer parsing, token
expiry/revocation and the actual rate limiter; the route manifest only declares
the budget, exactly like the rest of the core API.

The local-account directory, when supplied, is checked for
`verify_credentials`, status creation, favourite/unfavourite,
bookmark/unbookmark, follow/unfollow, relationships, notifications, media
management, account profiles, follower and following lists, list membership,
account statuses and account search. Without a directory the
core cannot distinguish local from remote
account ids; a host that enables this API should always pass one.

## Instance metadata

`GET {prefix}/instance` is public, rate-limited at `120/60`, and projects the
existing instance configuration and local stores instead of returning fixed
placeholder values. The contact and registration fields are optional config:

| Key | Field | Default |
| --- | --- | --- |
| `social/instance.description` | `description`, `short_description` | empty |
| `social/instance.version` | the Aloha Social part of `version` | `0.1.0` |
| `social/instance.contact_email` | `email` (`instance.email` is a fallback) | empty |
| `social/instance.contact_account` | `contact_account` | `null` |
| `social/instance.registrations` | `registrations` | `false` |
| `social/instance.approval_required` | `approval_required` | `true` |
| `social/instance.invites_enabled` | `invites_enabled` | `false` |
| `social/instance.languages` | `languages` (comma-separated or JSON array) | `[]` |
| `social/instance.thumbnail` | `thumbnail` | empty |

The `version` field keeps the Mastodon-compatible prefix and appends the
configured core version. `configuration.statuses`, `configuration.media_attachments`
and `configuration.polls` continue to reflect the real limits in
`Post`, `Attachment` and `Poll`; they are not configurable down through this
endpoint. `contact_account` is projected as the normal Mastodon account only
when it is configured and, if a `LocalActorDirectory` is supplied, that
account exists locally. A stale value therefore cannot advertise an account
that cannot be opened; without a directory the host remains responsible for
the configured id.

`stats` is derived from three optional capabilities over the existing stores:
`LocalActorCount` for `user_count`, `PostCount` for live `status_count` and
`RemoteDomainCount` for distinct live remote-post hosts. A store that does not
implement a capability reports `0` for that field; it is not an error, and the
endpoint does not fall back to an unbounded scan. The in-memory directory, both
post repositories and both remote-post repositories implement them, so the
standard assemblies report real values. The core `GET /api/v1/instance` embeds
the same description and statistics. There is no instance/statistics table,
migration or cleanup job: the values are read-only projections.

## Reading statuses

`GET /timelines/public` and `GET /accounts/{id}/statuses` return a JSON array
of status objects. `GET /statuses/{id}` returns one object. The mapping is:

- `id` is the core post id; it is opaque to clients.
- `account.id`/`acct`/`username` is the local user id. A full profile is
  host-owned; display name, avatar and counts are minimal defaults.
- `visibility`: `public`, `unlisted`, `private` (core followers-only) or
  `direct`.
- `content` is escaped text in a single `<p>`; `spoiler_text` is the content
  warning. A content warning implies `sensitive: true`.
- `media_attachments` projects the existing attachments, their alt text and
  original/thumbnail URLs. No new blob or media row is created by this API.
- `favourites_count` is the number of local accounts that favourited the post;
  `favourited` is true only when the authenticated viewer owns a favourite.
  The public timeline is unauthenticated, so it always reports `favourited`
  false while still reporting the count.
- `poll` is `null` when the status has no poll, otherwise the Mastodon poll
  object. Its options and counts come from the existing status row and the
  poll tables; no separate status model is created. `votes_count`,
  `voters_count` and each option count advance atomically with a local vote.
  `voted` and `own_votes` are viewer-specific; an unauthenticated reader sees
  `false`/`[]`, while an authenticated viewer gets their stored positions.
  `emojis` is always empty. `expired` is computed from `expires_at` and the
  clock, so it flips without a scheduled job.
- `uri`/`url` point at the core post URL; `created_at` is the stored publish
  time.

A followers-only status is readable by its author and by an accepted local
follower; a direct status by its author and its local recipients. Everyone else
gets `404 not_found`, like the core API, so the status route does not leak
existence. The account status list includes public and unlisted posts, plus
followers-only posts when the caller is the account itself or an accepted
local follower; direct posts and other viewers see no followers-only rows.
For an authenticated viewer the projected statuses also carry that viewer's
`favourited` and poll-vote state. `in_reply_to_id` is the parent post id,
`in_reply_to_account_id` its author (when the parent is still stored), and
`mentions` lists direct recipients. `replies_count` counts live direct replies,
`reblogs_count` counts local boosts, and `reblogged` reports whether the
authenticated viewer boosted the status. A reblog is projected as the outer
status with the booster as `account`, the boost id/timestamp, and the original
status nested under `reblog`; fetching that boost id through `GET /statuses/{id}`
returns the same wrapper.

## Private lists and the list timeline

Lists are private to the signed-in local account. They contain local account
ids only; the core does not fetch a remote list or store a remote membership.
All nine routes require a local account and are gated by `read:lists` or
`write:lists`.

```http
POST {prefix}/lists
Content-Type: application/x-www-form-urlencoded

title=Close%20friends&replies_policy=list&exclusive=true
GET {prefix}/lists
GET {prefix}/lists/list-abc
PUT {prefix}/lists/list-abc
Content-Type: application/x-www-form-urlencoded

title=Family&replies_policy=none&exclusive=false
DELETE {prefix}/lists/list-abc
```

A list object is `{id, title, replies_policy, exclusive}`. `title` is required
on create, trimmed and limited to 255 characters. `replies_policy` is
`followed` (the default), `list` or `none`; `exclusive` defaults to `false`.
`PUT` changes only the supplied fields and an empty update leaves the list
unchanged. `GET /lists` returns all lists of the caller, newest first, without
pagination. An id that exists for another user is indistinguishable from a
missing one and answers `404 not_found`.

```http
GET {prefix}/lists/list-abc/accounts?limit=40&max_id=bob
Link: <https://social.example/api/mastodon/v1/lists/list-abc/accounts?limit=40&max_id=bob>; rel="next"
POST {prefix}/lists/list-abc/accounts
Content-Type: application/x-www-form-urlencoded

account_ids[]=bob&account_ids[]=carol
DELETE {prefix}/lists/list-abc/accounts
Content-Type: application/x-www-form-urlencoded

account_ids[]=bob
```

The membership routes accept one to 100 `account_ids[]` values. Every id must
be a known local account; an unknown or remote id is `404 not_found` and the
whole write is refused. Adding an existing member or removing an absent one is
idempotent, and both writes answer `200 {}`. The accounts page defaults to 40
and is clamped to 1–80; `max_id`, `since_id`/`min_id` are
ownership-checked account cursors, combining the two directions is `422`, and
a non-empty page carries the usual `Link`.

```http
GET {prefix}/timelines/list/list-abc?limit=20&max_id=status-id
```

`GET /timelines/list/{list_id}` merges the members' local posts and boosts,
newest first, and applies the same visibility, block and mute rules as the
home timeline. Direct posts are always omitted; a followers-only post appears
only when the viewer follows its author. Replies are filtered by the list's
`replies_policy`: `none` removes every reply, `list` keeps replies to members,
and `followed` keeps replies to accounts the viewer follows or to the viewer.
The page uses the first-list argument as its cursor, defaults to 20, is clamped
to 1–40 and returns the usual status `Link`; the cursor must resolve to a
stored status or boost. A host without `AccountListRepository` answers `503
lists_not_configured`.

An `exclusive` list hides its members from `GET /api/v1/timelines/home` for
its owner. The local-follow decorator applies the same filter to their posts
and to boosts authored by them or of their posts. The membership table is not
a second follow graph and does not affect followers/following counts or the
ActivityPub collections.

## Reading local profiles and follow lists

`GET /accounts/{id}` returns the same minimal Mastodon account object used by
statuses and notifications. It is public and rate-limited at `120/60`. An
unknown id, a remote actor URL or an account the supplied local directory does
not know is `404 not_found`; the route does not reveal whether an account ever
existed. The id is the local user id, and the counts come from the existing
follower graph: `followers_count` is the follower collection and
`following_count` merges outgoing remote edges with local-to-local edges.
Profile detail fields stay host-owned; the local id and counts are projected
as above, while display name, note, avatar, header, fields and
`statuses_count` keep their minimal defaults instead of inventing host data.

`GET /accounts/{id}/followers` and `GET /accounts/{id}/following` are also
public (`60/60`) and return an array of the same account objects. `limit`
defaults to 40 and is clamped to 1–80. Both support Mastodon's id-aware
cursors:

```http
GET {prefix}/accounts/bob/followers?limit=20&max_id=alice
Link: <https://social.example/api/mastodon/v1/accounts/bob/followers?limit=20&max_id=alice>; rel="next"
```

`max_id` returns items after the cursor, while `since_id` (or its alias
`min_id`) returns items before it, newest first. A cursor must belong to the
requested collection; a missing or foreign cursor is `404 not_found`, and
combining `max_id` with `since_id`/`min_id` is `422 validation_failed`. The
`Link` header points at the next page when one exists. A local follower edge
stores its actor as an absolute local URL; the list projects that URL back to
the local user id, so client code sees the same id as everywhere else. Remote
follow edges from `social_following` are projected as minimal remote accounts
(`id`/`url` are the actor URL, `username`/`acct` come from the URL) rather
than fetched profiles. The lists need the relationship service; an assembly
without it answers `503 follows_not_configured`. There is no new table, column
or migration: the routes read the existing follower/outgoing graphs.

## Writing and deleting

`POST /statuses` accepts `application/json` or
`application/x-www-form-urlencoded`:

```json
{
  "status": "hello",
  "visibility": "public",
  "spoiler_text": "optional",
  "sensitive": false,
  "media_ids": ["existing-attachment-id"],
  "poll": {
    "options": ["Yes", "No"],
    "expires_in": 3600,
    "multiple": false,
    "hide_totals": false
  }
}
```

`private` maps to followers-only. `direct` uses the local recipient model:
recipients come from the `recipient_ids` extension (a list) or, when that is
absent, from `@local-id` mentions in the status text. `@user@remote.example` is
not reduced to a local id. A direct status with no local recipient is `422`.

`in_reply_to_id` is the parent post id. The parent must be visible to the
caller; a direct parent requires `visibility=direct`, and a followers-only
parent refuses a public/unlisted reply. Empty content, over-limit text, more
than four attachments and an attachment not owned by the caller are refused by
`PostService` with `422 validation_failed` and `error.fields`.

`poll` is optional and is stored atomically with the status. It accepts two to
four `options`, each after trimming one to 50 characters, plus exactly one of
`expires_in` (integer seconds, 300–604800) or `expires_at` (ISO date/time). The
resulting expiry must be between 300 and 604800 seconds from the request time;
when both forms are present, `expires_in` wins. `multiple` and `hide_totals`
default to `false` and accept JSON booleans, `0`/`1` and the usual
`true`/`false`/`yes`/`no`/`on`/`off` form values. Invalid options, expiry or
booleans are `422 validation_failed`; the handler validates before calling
`PostService`, so a rejected poll creates no post, attachment link or poll row.
The same route then returns the status with `poll` populated, and `GET
/statuses/{id}`, timelines, account statuses and search return the same object.

### Voting and reading a poll

`POST {prefix}/polls/{id}/votes` records the caller's choices and returns the
updated Mastodon poll object. The poll id is the stable `poll-<postId>` value
from the status. The body accepts JSON `{"choices":[0,2]}` or the usual form
encoding `choices[]=0&choices[]=2`; positions are zero-based. A single-choice
poll accepts exactly one position; a multiple-choice poll accepts one to four
unique positions. An unknown position, a duplicate, an empty list, a
non-numeric value, a poll for an invisible/deleted status, a poll without a
row, or a malformed id is `422 validation_failed` or `404 not_found` as
appropriate. A vote on an ended poll is `422 poll_ended` and writes nothing.

The route requires `write:statuses`, declares `30/900`, and stays behind the
Mastodon gate. An identical retry is an idempotent success; a different second
vote by the same account is `422 already_voted`. The composite
`(post_id, user_id)` key is the race-safe boundary. Votes live in
`social_poll_vote`; an assembly without a vote repository answers `503
polls_not_configured` rather than silently accepting a write. A poll on a
followers-only status is readable and votable by its author and by an accepted
local follower, through the same visibility rule as statuses; everyone else
gets `404`.

`GET {prefix}/polls/{id}` is public and returns the poll object; when the host
supplies an authenticated user id it includes that viewer's `voted` and
`own_votes`. Without a vote repository, or before the host has created its
table, the read still works but reports `voted: false`; only the write route
answers `503 polls_not_configured`. Poll voting is local bookkeeping only and does not federate an
ActivityPub `Question`; remote poll fetching/voting and federation remain
external.

Poll creation does not federate an ActivityPub `Question` in this slice.

`DELETE /statuses/{id}` soft-deletes the caller's status and returns `204`.
Another account's status, a missing status or an already deleted status is
`404 not_found`; the ownership check happens in `PostService`, not in a
handler copy.

### Favouriting statuses

`POST /statuses/{id}/favourite` records the signed-in local account's
favourite and returns the same Mastodon status shape with `favourited: true`
and an incremented `favourites_count`. `POST /statuses/{id}/unfavourite`
removes only that account's favourite and returns `favourited: false`.

Both endpoints require `write:favourites`, declare a `30/900` rate limit and
are idempotent: repeating a favourite returns the existing count without a
second row, and unfavouriting an absent favourite is a successful no-op. The
composite `(user_id, post_id)` primary key in `social_favourite` is the
idempotency boundary, so no client key is needed. The local-account directory
and the same visibility rule as status reads apply: a missing, deleted,
private or otherwise invisible status is `404 not_found`, and a non-local
account is refused before any write.

```json
{
  "id": "…",
  "favourites_count": 1,
  "favourited": true,
  "…": "the usual status fields"
}
```

An assembly without a `FavouriteRepository` answers `503
favourites_not_configured` instead of silently dropping the write. The normal
`Application::inMemory()`/`Application::forHost()` assemblies always supply
one.

### Bookmarking statuses

`POST /statuses/{id}/bookmark` stores the signed-in local account's private
bookmark and returns the same Mastodon status shape with `bookmarked: true`.
`POST /statuses/{id}/unbookmark` removes only that account's bookmark and
returns `bookmarked: false`. Both require `write:bookmarks`, declare a
`30/900` rate limit, and are idempotent through the composite
`(user_id, post_id)` primary key in `social_bookmark`.

A bookmark is not a reaction: it has no public count, is never shown to
another account and records no notification. The same local-account directory
and visibility rule as status reads apply; missing, deleted, private or
otherwise invisible statuses answer `404 not_found`. An assembly without a
`BookmarkRepository` answers `503 bookmarks_not_configured`.

`GET /bookmarks` returns the caller's private statuses newest-first. The
cursor is the status id of a bookmark owned by the caller: `limit` accepts
1–40 (default 20), `max_id` selects older bookmarks, `since_id`/`min_id`
select newer ones, and combining `max_id` with either newer cursor is `422
validation_failed`. An unknown or foreign cursor is `404 not_found`, so it
cannot probe another account's list. Statuses that have since been deleted or
become invisible are skipped. A non-empty page carries the usual `Link` with
`rel="next"`. The list requires `read:bookmarks` and declares `60/60`.

### Muting accounts

`POST /accounts/{id}/mute` stores one private mute for the signed-in local
account. The target is either a local account id or an absolute remote actor
URL; an unknown bare id is `404 not_found` and muting yourself is `422
validation_failed`. The response is the Mastodon relationship with
`muting: true` and `muting_notifications` reflecting the stored setting:

```json
{
  "id": "bob",
  "following": false,
  "muting": true,
  "muting_notifications": true,
  "…": "the usual relationship fields"
}
```

Both `notifications` and `duration` are optional. `notifications` defaults to
false: every mute hides the target from the caller's home timeline, while a
true value additionally hides events caused by that target from
`GET /notifications` and `GET /notifications/unread_count`. `duration` is a
positive integer number of seconds; absent, empty or `0` means indefinite, and
values above one year are `422 validation_failed`. Repeating the call updates
the same row and restarts the expiry from the current request time. The
composite `(user_id, actor_id)` primary key in `social_mute` is the
idempotency boundary, so no client key is needed.

Active mutes hide the target's posts and boosts from the home timeline,
including a boost whose object author is muted. They do not remove the post
from its own account route and do not affect other accounts. Expiry is
evaluated on read: an expired row stays stored but no longer hides anything,
and re-muting replaces it. `muting` and `muting_notifications` are also
returned by `GET /accounts/relationships` for local accounts, and `muted` is
set on statuses for the authenticated viewer.

`POST /accounts/{id}/unmute` removes only the caller's row, answers with
`muting: false`, and is a successful no-op when the mute is already absent or
expired. Both writes require `write:mutes` and declare a `30/900` rate limit.
An assembly without a `MuteService` answers `503 mutes_not_configured`.

`GET /mutes` returns the caller's active mutes as Mastodon accounts,
newest-first. `limit` accepts 1–80 (default 40), `max_id` selects older mutes,
`since_id`/`min_id` select newer ones, and combining `max_id` with either
newer cursor is `422 validation_failed`. Because Mastodon has no separate mute
id, the cursor is the target account id (a local id or the remote actor URL)
of one of the caller's active mutes; an unknown, expired or foreign target is
`404 not_found`. A non-empty page carries the usual `Link` with `rel="next"`.
The list requires `read:mutes` and declares `60/60`.

### Reblogging and reply context

`POST /statuses/{id}/reblog` shares a visible local public or unlisted status
and returns the Mastodon wrapper: the outer object uses the boost id and time,
carries the booster as `account`, and nests the original status under
`reblog`. Repeating the call returns the same wrapper and does not create a
second row. Only public and unlisted posts can be boosted; a followers-only or
direct post is a `422 validation_failed`, because announcing it to the
booster's followers would create a new audience. `POST
/statuses/{id}/unreblog` removes only the caller's boost and is a successful
no-op when absent. Both require `write:statuses` and declare a `30/900` rate
limit.

The local `(user_id, post_id)` unique key in `social_boost` is the idempotency
boundary. A boost writes one `Announce` addressed to the public collection and
queues it to the booster's followers through the existing delivery queue; an
unboost queues an `Undo` that embeds the original `Announce`. The local row is
committed first, so a queue or configuration failure cannot fail the request or
roll the boost back. A boost notification is recorded for the post author
(never for a self-boost) and is exposed as `reblog`. `reblogs_count` and
`reblogged` are projected from the same table.

`GET /statuses/{id}/context` returns
`{"ancestors": [...], "descendants": [...]}`. Ancestors walk the
`in_reply_to_id` chain oldest-first and stop at the first post the viewer
cannot see; descendants breadth-first through visible direct replies, oldest
first, capped at 40. A private ancestor therefore never leaks through a public
reply. The route is public and rate-limited at `60/60`; an authenticated
viewer's visibility applies to every row.

Status payloads now expose `replies_count`, `reblogs_count` and `reblogged`.
The reply count is the number of live direct replies; deleted replies are
excluded. A reblog is also readable by its boost id through `GET
/statuses/{id}`, which returns the same nested wrapper.

In the core API the same write is `POST /api/v1/posts/{id}/boost` (201 on a new
boost, 200 on a repeat) and `DELETE /api/v1/posts/{id}/boost` (204, idempotent).
An assembly without a `BoostRepository`/`BoostService` answers `503
boosts_not_configured` instead of silently dropping the write.

### Following accounts and relationships

`POST /accounts/{id}/follow` follows a **local** account from the signed-in
local account. The target id is the local directory id used by the rest of
this API, not a remote actor URL. A self-follow is `422 validation_failed`
with `error.fields.id`; an unknown or non-local target is `404 not_found`.
The write is idempotent: repeating the follow returns the same relationship
and does not create a second edge. `POST /accounts/{id}/unfollow` removes only
the caller's edge and is a successful no-op when the account was not followed.

The edge is stored in the existing incoming graph (`social_follower`) with
`is_local = 1`, because the same graph already answers follower counts and the
public followers collection. Local edges are excluded from
`FollowerRepository::inboxesFor()`, so a local follow never queues a remote
delivery or a signed `Follow`. A local follow appears in the target's public
followers collection and in the caller's public following collection; remote
follow edges stay in `social_following` and count through the existing
outgoing service. The authenticated core home timeline
(`GET /api/v1/timelines/home`) also includes live local posts by accounts the
reader follows locally; blocking one of those accounts hides it from the
reader without deleting the follow edge. This is a local relationship only —
following a remote actor through this compatibility prefix is not part of the
slice; the core `POST /api/v1/following` remains the federation path.

`GET /accounts/relationships` accepts either a scalar `id` or repeated
`id[]` parameters:

```http
GET {prefix}/accounts/relationships?id[]=bob&id[]=carol
```

It returns one object per requested id, in request order, without duplicates.
At most 100 unique ids are accepted; a missing or empty `id` is `422
validation_failed`, and any unknown local id makes the whole request `404
not_found` so the response cannot silently omit a target. The fields follow
the Mastodon relationship shape:

```json
{
  "id": "bob",
  "following": true,
  "showing_reblogs": true,
  "notifying": false,
  "languages": [],
  "followed_by": false,
  "blocking": false,
  "blocked_by": false,
  "muting": false,
  "muting_notifications": false,
  "requested": false,
  "domain_blocking": false,
  "endorsed": false,
  "note": ""
}
```

`following` and `followed_by` are true only for local edges in this slice;
`blocking` reads the existing per-user block repository. Relationship reads
and the two writes require a `LocalRelationshipService` and a local account
directory. `Application::inMemory()`/`Application::forHost()` create the
service when a directory is supplied; an assembly without one answers `503
follows_not_configured` rather than returning an empty relationship.

### Reading and dismissing notifications

`GET {prefix}/notifications` returns the signed-in local account's unread
notifications, newest first. A local favourite by another account creates one
`favourite` notification for the post author; a local or remote follow creates
one `follow` notification for the local target; a reply or direct post creates
one `mention` notification for its parent author/recipients; and a local boost
creates one `reblog` notification for the post author. Repeating the same
favourite, follow, reply or boost does not create a second row. The API does not
notify an account about its own actions and does not create a notification for
a post the caller cannot see.

```json
{
  "id": "notification-8f14e45fceea167a5a36dedd4bea2543",
  "type": "favourite",
  "created_at": "2026-11-11T10:00:00.000Z",
  "unread": true,
  "account": {
    "id": "alice",
    "username": "alice",
    "acct": "alice",
    "url": "https://social.example/@alice",
    "…": "the usual account fields"
  },
  "status": {
    "id": "…",
    "…": "the usual status fields"
  }
}
```

`account` is the actor who caused the event: a local account id for a local
favourite or follow, and the absolute actor URL for a remote follower. A
remote account is projected with the URL path as `username`, `username@host`
as `acct`, the actor URL as `url`, and zero counts; the core does not fetch a
remote profile just to render a notification. `status` is present for a
favourite, mention and reblog and omitted for a follow. It is also omitted if the post was deleted
or is no longer visible to the recipient, so a notification never exposes a
tombstone. The id is derived from recipient, type, actor and post, which is the
idempotency boundary; no random notification id is generated. `unread` is true
for every row the list returns; the list never includes read rows.

The endpoint accepts `types[]` and `exclude_types[]`, each repeatable. Known
local types are `favourite`, `follow`, `mention` and `reblog`; unknown names are ignored for
forward compatibility. If a non-empty `types[]` contains no known type, the
result is an empty array. The filters apply before the page limit.

Events caused by an account whose mute has `notifications: true` are omitted
from both the list and the unread count. The rows themselves are not deleted,
so unmuting makes historical events visible again; this is the same read-time
filter as the home timeline.

`limit` defaults to 20 and is clamped to 1–40. `max_id` returns notifications
older than the cursor, `since_id` (or `min_id`) returns newer ones, and a
notification id belongs to exactly one recipient: a cursor that exists for a
different account is `404 not_found`, not someone else's page. `max_id` cannot
be combined with `since_id`/`min_id`. A non-empty page carries `Link: <…
?limit=…&max_id=…>; rel="next"` and preserves active type filters.

#### Dismissing and clearing

`POST {prefix}/notifications/{id}/dismiss` marks one notification read. It
requires `write:notifications`, declares a `30/900` rate limit and answers
`200 {}` (the same empty-object shape Mastodon uses). The id is scoped to the
caller: a missing or foreign notification answers `404 not_found`, while a
repeat dismiss of the caller's own already-read row answers `200 {}` again.
No row is deleted, so a repeated event cannot resurrect an old notification
and the host retains the historical event for its own retention window.

`POST {prefix}/notifications/clear` marks every unread notification of the
caller read. It requires `write:notifications`, declares a `30/900` rate limit
and answers `200 {}`. Repeating clear is a successful no-op. A favourite or
follow recorded after the clear is a new unread row; clear does not store a
"read everything until" watermark that could hide future events.

`GET {prefix}/notifications/unread_count` requires `read:notifications`,
declares a `60/60` rate limit and returns `{"count": n}`. `count` defaults to a
cap of 100 and accepts `limit` from 1 to 1000, matching Mastodon's capped
count rather than a full table count. The same `types[]`/`exclude_types[]`
filters as the list apply; `account_id` limits the count to the event actor,
using a local account id or the stored remote actor URL. Unknown types are
ignored, and a non-empty `types[]` with no known local type returns zero. The
count uses the optional repository `countUnread()` read capability and never
loads notification rows.

A manually assembled service without a `NotificationRepository` answers `503
notifications_not_configured` instead of returning an empty list. The standard
`Application::inMemory()`/`Application::forHost()` assemblies always supply
one. Reading the list does not mark anything read; only dismiss and clear set
the durable `read_at` marker. A missing or unreadable table answers `503
notifications_unavailable` for all four notification routes.

### Searching local accounts and statuses

`GET {prefix}/search` implements the Mastodon v2 search object over local data
only:

```http
GET {prefix}/search?q=hello&type=statuses&limit=20&following=true
```

```json
{
  "accounts": [],
  "statuses": [{"id": "…", "content": "<p>hello</p>", "…": "the usual status fields"}],
  "hashtags": []
}
```

`q` is required and non-empty (`422 validation_failed`). `type` is optional
and accepts `accounts`, `statuses` or `hashtags`; any other value is `422
validation_failed`. Without `type`, all three collections are returned.
`hashtags` is always an empty array because the core has no hashtag model.
Status matches are case-insensitive over live, public/unlisted
`social_post.content`; private, direct and soft-deleted posts never appear,
and the projected status keeps the normal viewer flags and attachment shape.
Account matches are case-insensitive over the host's local account directory
and are projected with `MastodonPresenter::account`.

`following=true` limits both collections to local accounts the signed-in user
follows (and, for statuses, to posts by those accounts). It does not follow or
resolve anyone. `limit` defaults to 20 and is clamped to 1–40; `offset`
defaults to 0 and is clamped to 0–1000, so search uses offset pagination rather
than the id cursor of timelines. `resolve` and `exclude_unreviewed` are
accepted for client compatibility but have no local effect: the endpoint never
fetches a remote status or profile and has no review queue. `read:search` is
required; a parent `read` token also grants it. The full result object is
returned even when one collection is empty.

The two capabilities are optional ports over the existing stores:
`PostSearchRepository` and `LocalActorSearch`. The standard
`Application::inMemory()`/`Application::forHost()` assemblies wire these when
the supplied post store and account directory implement them. A host whose
post store does not implement search, or whose directory has no search
capability, gets `503
search_not_configured` when the affected collection is requested. Status
search needs no new table or index; a host with a large post table can add its
own index or search adapter behind the same port without changing the route.
Search is read-only, so it creates no rows and has no cleanup job.

### Uploading media

`POST /media` accepts `multipart/form-data` with a `file` field and an optional
`description` field, the same envelope as `POST /api/v1/media`. `alt_text` is
accepted as a fallback for clients that used the core name. The bytes are
validated and stored by the same `MediaService`: JPEG, PNG and WebP up to 5
MiB and 40 megapixels, with a bounded thumbnail. `focus` is ignored because the
core has no crop metadata. A successful upload answers `200` with a Mastodon
attachment:

```json
{
  "id": "…",
  "type": "image",
  "url": "https://social.example/api/v1/media/…",
  "preview_url": "https://social.example/api/v1/media/…?variant=thumbnail",
  "remote_url": null,
  "preview_remote_url": null,
  "text_url": null,
  "meta": {"original": {"width": 1200, "height": 800,
    "size": "1200x800", "aspect": 1.5}, "small": {…}},
  "description": "A cat on a keyboard",
  "blurhash": null
}
```

Use the returned `id` in `media_ids` on `POST /statuses`. A rejected upload
answers `400 invalid_multipart`, `413 payload_too_large`, `422
validation_failed` with `error.fields.file` or `error.fields.description`, or
`503 media_not_configured`/`media_storage_unavailable`; it leaves neither a
metadata row nor a blob. An instance without a `BlobStore`-backed
`MediaService` cannot serve this route and answers `503 media_not_configured`.

An optional `Idempotency-Key` makes a retry safe. The core derives a
deterministic internal `source_url` from the local user and the key and passes
it to `MediaService`, whose existing source-URL lookup returns the original
attachment before writing again. The key is scoped to the user, a repeat
returns the same attachment with no second row or blob, and a different key
creates a distinct attachment. A failed first attempt stores nothing, so a
retry can succeed. This needs no second table or cleanup job.

### Updating and deleting media

`PUT /media/{id}` updates the description of an owned, unattached attachment.
It accepts `application/json`, `application/x-www-form-urlencoded` and
`multipart/form-data`, reuses the same multipart parser as upload, and returns
the usual Mastodon attachment shape. `description` is optional: omitting it
leaves the current value unchanged, while an empty value clears it. `alt_text`
is accepted as a fallback. `focus` is ignored because the core has no focal
point metadata, and `file`/`thumbnail` are refused with `422
unsupported_parameter` rather than silently discarded.

Only an attachment owned by the caller and not linked to any local post can be
updated; a missing, foreign or attached id is `404 not_found`. The bytes and
storage keys never change, and no new row is created.

`DELETE /media/{id}` removes an owned attachment that is not linked to a post,
including its original and thumbnail blobs, and answers the Mastodon empty
object with status `200`:

```json
{}
```

An attached attachment is refused with `422 validation_failed` and the message
`Media attachment is currently used by a status.`; a missing or foreign id is
`404 not_found`. Both routes require `write:media`, declare a `30/900` rate
limit and are unavailable before auth when `social/mastodon.api` is off. A
soft-deleted post still counts as a link: its `social_post_attachment` row
survives the tombstone, so a host that wants to reclaim the media must remove
that link in its own cleanup path.

### Status idempotency

Send `Idempotency-Key: <client-key>` on `POST /statuses`. The core derives a
deterministic post id from the local user id and the key and passes it to
`PostService`. The first request inserts; a retry hits the unique post id,
re-reads that row and returns the same status instead of inserting a second
one. There is no extra idempotency table and no cleanup job. The key is
scoped to the user, so two users may use the same client key.

Repeating a key with different content returns the original status, as an
idempotency key should. Poll rows are part of the same transaction and are
returned from the original post on a retry, so they are not duplicated. A
failed validation or storage attempt writes no row and no delivery job; a later
retry may succeed. Keys are meant to be unique per logical write, not per
request body.

## Pagination

`limit` defaults to 20 and is clamped to 1–40. A value outside the range is
first accepted as an integer and then clamped; a non-integer falls back to the
default.

- `max_id` returns entries older than that cursor, using the stored time and
  id as a tie-break so several posts in the same second cannot repeat or
  disappear.
- `since_id` returns entries newer than that cursor.
- `min_id` is accepted as the same newer-than cursor when `since_id` is
  absent.
- `max_id` cannot be combined with `since_id`/`min_id`; that is `422
  validation_failed`.
- An unknown cursor id is `404 not_found`; a notification cursor must belong to
  the signed-in recipient.
- Search uses a numeric `offset` instead of a cursor: it is clamped to 0–1000
  and applied after the visibility/author filter, so a page never contains a
  non-public or deleted status.

A non-empty page carries `Link: <…?limit=…&max_id=…>; rel="next"`. Clients can
follow it until the page is empty. This uses the existing post or notification
repository's id-aware cursor; the API does not keep a per-client timeline.

## Errors

The normal core JSON error shape applies. The compatibility-specific codes are:

| Code | Status | When |
| --- | --- | --- |
| `mastodon_api_disabled` | 404 | `social/mastodon.api` is off |
| `insufficient_scope` | 403 | The token lacks the route's required scope |
| `favourites_not_configured` | 503 | The assembly has no `FavouriteRepository` |
| `bookmarks_not_configured` | 503 | The assembly has no `BookmarkRepository` |
| `mutes_not_configured` | 503 | The assembly has no `MuteService` |
| `lists_not_configured` | 503 | The assembly has no `AccountListRepository` |
| `notifications_not_configured` | 503 | The assembly has no `NotificationRepository` |
| `notifications_unavailable` | 503 | The notification table is missing or cannot be read; run the schema migration |
| `follows_not_configured` | 503 | The assembly has no local account directory or `LocalRelationshipService` |
| `search_not_configured` | 503 | The assembly has no `PostSearchRepository` or `LocalActorSearch` for the requested collection, or no relationship service for `following=true` |
| `polls_not_configured` | 503 | The assembly has no `PollVoteRepository`, or the vote table is missing |
| `poll_ended` | 422 | A vote was attempted after the poll expiry |
| `already_voted` | 422 | The caller already stored different choices in this poll |
| `instance_not_configured` | 503 | `social/instance.url` is missing; a local follow needs it to derive actor URLs |
| `media_not_configured` | 503 | Media upload has no `BlobStore`-backed `MediaService` |
| `media_storage_unavailable` | 503 | A validated upload could not be written to the blob store |
| `unsupported_parameter` | 422 | Replacing media bytes (`file`/`thumbnail`) in this slice |
| `validation_failed` | 422 | Missing/invalid status fields, poll options/expiry/choices, media ids, relationship ids, search `q`/`type`, mute duration/notification values or a self-follow/self-mute; see `error.fields` |
| `not_found` | 404 | Missing/private status or poll, unknown cursor, unknown local account, unknown relationship target or invalid mute target |

Authentication failures use the existing `401 authentication_required`; the
host's limiter should use `429` with its own retry metadata. The core does not
invent a second error shape for the compatibility surface.

## Database and cleanup

Statuses live in `social_post` / `social_post_attachment`; uploaded images use
`social_attachment` and the same `BlobStore` as `/api/v1/media`. Polls add
`social_poll` and ordered `social_poll_option` rows from `PollSchema`, keyed by
`post_id` and written in the same transaction as the post. Existing hosts must
create both tables before accepting a `poll` parameter or serving statuses
whose repository reads the poll table; a fresh host gets them from
`PollSchema::create()`. Voting additionally requires `social_poll_vote`:

```sql
CREATE TABLE social_poll_vote (
  post_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(255) NOT NULL,
  positions VARCHAR(32) NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  PRIMARY KEY (post_id, user_id)
);
CREATE INDEX social_poll_vote_user ON social_poll_vote (user_id, created_at);
```

`positions` is a sorted comma-separated list of zero-based option indexes and
is written together with the poll/option count increments in one transaction.
An existing host must add the table before enabling local votes; the route
answers `503 polls_not_configured` when the vote repository or table is absent,
while status and poll reads degrade to `voted: false` until it exists. Poll
rows, options and votes are removed with the post's hard-delete cleanup, while
a soft-deleted post leaves them as a tombstone like favourites and attachments.
There is no separate expiry row: `expired` is derived at read time. Status
idempotency uses the primary key and a deterministic post id; media
idempotency uses the existing unique `source_url` column with a deterministic
internal key. Media update changes only `social_attachment.alt_text`; media
delete removes that row and both blobs, but only while no `social_post_attachment`
row references it. Neither operation adds a table or migration. Local
favourites use the new `social_favourite` table from
`FavouriteSchema`. Local follow edges add one column to the existing
`social_follower` table from `FollowerSchema`:

```sql
CREATE TABLE social_favourite (
  user_id VARCHAR(255) NOT NULL,
  post_id VARCHAR(64) NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  PRIMARY KEY (user_id, post_id)
);
CREATE INDEX social_favourite_post ON social_favourite (post_id);
CREATE INDEX social_favourite_user ON social_favourite (user_id, created_at);

CREATE TABLE social_bookmark (
  user_id VARCHAR(255) NOT NULL,
  post_id VARCHAR(64) NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  PRIMARY KEY (user_id, post_id)
);
CREATE INDEX social_bookmark_post ON social_bookmark (post_id);
CREATE INDEX social_bookmark_user ON social_bookmark (user_id, created_at, post_id);

CREATE TABLE social_mute (
  user_id VARCHAR(255) NOT NULL,
  actor_id VARCHAR(512) NOT NULL,
  notifications SMALLINT NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  expires_at VARCHAR(20) NULL,
  PRIMARY KEY (user_id, actor_id)
);
CREATE INDEX social_mute_user ON social_mute (user_id, created_at, actor_id);
CREATE INDEX social_mute_actor ON social_mute (actor_id);

CREATE TABLE social_account_list (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  user_id VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  replies_policy VARCHAR(16) NOT NULL,
  exclusive SMALLINT NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  updated_at VARCHAR(20) NOT NULL
);
CREATE INDEX social_account_list_user
  ON social_account_list (user_id, created_at, id);

CREATE TABLE social_account_list_member (
  list_id VARCHAR(64) NOT NULL,
  account_id VARCHAR(255) NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  PRIMARY KEY (list_id, account_id)
);
CREATE INDEX social_account_list_member_account
  ON social_account_list_member (account_id);
CREATE INDEX social_account_list_member_list
  ON social_account_list_member (list_id, created_at, account_id);

CREATE TABLE social_boost (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  user_id VARCHAR(255) NOT NULL,
  post_id VARCHAR(64) NOT NULL,
  created_at VARCHAR(20) NOT NULL,
  UNIQUE (user_id, post_id)
);
CREATE INDEX social_boost_post ON social_boost (post_id);
CREATE INDEX social_boost_user ON social_boost (user_id, created_at);
CREATE INDEX social_boost_timeline ON social_boost (created_at, id);
```

```sql
ALTER TABLE social_follower ADD COLUMN is_local SMALLINT NOT NULL DEFAULT 0;
```

Existing follower rows default to `is_local = 0`, which is exactly the old
remote-delivery meaning, so the migration needs no backfill. Only
`POST /accounts/{id}/follow` writes `is_local = 1`; unfollow removes the row.
An existing host must add the column before exposing the follow routes; a
fresh host gets it from `FollowerSchema::create()`. A host that leaves the old
schema in place will fail on follower reads and on follow/unfollow. There is
no cleanup job for the marker: it is part of the edge and disappears with it.

```sql
CREATE TABLE social_poll (
  post_id VARCHAR(64) NOT NULL PRIMARY KEY,
  multiple SMALLINT NOT NULL,
  hide_totals SMALLINT NOT NULL,
  expires_at VARCHAR(20) NOT NULL,
  votes_count INTEGER NOT NULL DEFAULT 0,
  voters_count INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX social_poll_expiry ON social_poll (expires_at);

CREATE TABLE social_poll_option (
  post_id VARCHAR(64) NOT NULL,
  position INTEGER NOT NULL,
  title VARCHAR(255) NOT NULL,
  votes_count INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id, position)
);
CREATE INDEX social_poll_option_post ON social_poll_option (post_id);
```

```sql
CREATE TABLE social_notification (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  recipient_id VARCHAR(255) NOT NULL,
  actor_id VARCHAR(255) NOT NULL,
  type VARCHAR(32) NOT NULL,
  post_id VARCHAR(64) NULL,
  created_at VARCHAR(20) NOT NULL,
  read_at VARCHAR(20) NULL
);
CREATE INDEX social_notification_timeline
  ON social_notification (recipient_id, created_at, id);
CREATE INDEX social_notification_unread
  ON social_notification (recipient_id, read_at, created_at, id);
```

An existing host must create `social_favourite` through its migration path
before exposing the two favourite routes; there is no data backfill. A fresh
host can run `FavouriteSchema::create()`. The private bookmark routes and list
need the `social_bookmark` table from `BookmarkSchema::create()`; its
composite primary key is the idempotency boundary and its
`(user_id, created_at)` index serves the cursor. There is no backfill and no
public count. Mute routes, relationship/status flags, home-timeline filtering
and notification filtering need `social_mute` from `MuteSchema::create()`;
its composite primary key is the upsert boundary, its
`(user_id, created_at, actor_id)` index serves the list cursor and its
`expires_at` is compared with `now` on read. There is no backfill and no public
count; an expired row can be pruned by the host at any time. List routes and
the exclusive home filter need both tables from `AccountListSchema::create()`;
membership is keyed by `(list_id, account_id)`, so a repeated add is
idempotent, and deleting a list removes its membership rows. There is no
backfill and no remote membership. Boost/reblog routes and status counts need `social_boost` from
`BoostSchema::create()` for the same reason; a boost row survives a post
soft-delete for the host's cleanup policy. An existing host
must likewise create
`social_notification` with `NotificationSchema::statements()` or
`NotificationSchema::create()` before enabling the notification routes; the
standard `forHost()` assembly constructs the SQL repository, but it does not
alter an existing database. Recording is best-effort: while the table is
missing, a favourite or follow still succeeds, the notification failure is
logged, and repeating the event after the migration can heal the row. The
notification routes answer `503 notifications_unavailable` until the table
exists.

An existing `social_notification` table from the listing-only slice needs the
read marker before dismiss, clear or unread-count can work:

```sql
ALTER TABLE social_notification ADD COLUMN read_at VARCHAR(20) NULL;
CREATE INDEX social_notification_unread
  ON social_notification (recipient_id, read_at, created_at, id);
```

Existing rows have `read_at = NULL`, so they remain unread after the upgrade;
there is no backfill and no notification is deleted. The insert query now also
writes `read_at`, and the list selects it, so complete the migration before
enabling any notification route; an old table without the column answers `503
notifications_unavailable` instead of serving a partial read state. Fresh
hosts get the column and both indexes
from `NotificationSchema`.

There is no backfill from old favourites or followers: only events recorded
after the table exists appear. Deleting a post
follows the normal soft-delete path and deliberately leaves favourite,
poll, option and notification rows for an explicit host cleanup; the post is
not visible, so its count and status are not served. Deleting a follower or
unfavouriting removes the interaction, not its historical notification.
Notification rows, including read rows, can be removed by recipient or by age
in a host cleanup job; there is no scheduled core job and no separate
read-state table. Poll cleanup should delete options first or
rely on the host's `ON DELETE CASCADE`; the core schema does not add a foreign
key. Attachment rows and blobs remain governed by the existing media
cleanup policy, not duplicated here.

## OpenAPI and tests

The forty-three routes are in the manifest with auth, scope and rate-limit metadata.
`bin/export-openapi` includes them with the `mastodon` tag and
`x-required-scopes`. `MastodonApiTest` drives the real kernel through instance metadata,
configured contact/registration fields and local stats (including stale contact
accounts and the core projection), media upload (success, description, media-in-status, auth, scope, disabled,
missing/invalid/corrupt/oversized file, no orphan rows or blobs, idempotency),
media update (JSON/form/multipart, absent/empty description, ownership,
attached refusal, binary refusal) and delete (empty object, blob cleanup,
attached refusal, foreign/missing ids, scopes and disabled feature),
timeline, create (JSON and form), poll creation (JSON/form, `expires_in` and
`expires_at`, expiry flip, options/expiry/boolean validation with no partial
rows, idempotent retry, instance limits), poll read/voting (viewer state,
multiple choices, idempotent retry, changed-vote rejection, expiry, private
visibility, missing ids, auth/scope/disabled and SQL counters), read, local favourite/unfavourite (counts,
viewer flags, repeat idempotency, absent-unfavourite no-op, auth, scope,
deleted status and custom prefix), private bookmarks (pair idempotency,
viewer flag, newest-first list, cursor pages, auth/scope/disabled, invisible
and deleted statuses), local mutes (pair/upsert idempotency, notification flag,
duration/expiry, `muting`/`muted` projection, home-timeline and notification
filtering, remote actor list projection, cursor, self/unknown/invalid
settings, auth/scope/disabled), private lists (CRUD, defaults and trimming,
ownership, membership add/remove idempotency, account and status cursors,
list timeline visibility, replies policies, boosts, mutes and the exclusive
home filter, auth/scope/disabled), local follow/unfollow (relationship flags,
idempotency, self-follow, unknown target, home-timeline visibility, scopes,
disabled feature), and
relationship reads (scalar and `id[]`, deduplication, missing/empty input,
unknown ids and the 100-id limit), notifications (favourite/follow creation,
repeat idempotency, historical survival, type filters, pagination, cursor
ownership, auth/scope/disabled), notification dismiss/clear/unread_count (idempotency, foreign/missing ids, read-row hiding, count caps/filters, auth/scope/disabled and storage failure), local search (account/status matches, type
filters, following filter, offset pages, private/deleted exclusion, missing
`q`/invalid type, auth/scope/disabled and missing capability), account
profiles and paged local follower/following lists (known/unknown/remote
accounts, local actor-URL mapping, remote target projection, cursor ownership,
`max_id`/`since_id`/`min_id`, limits, `Link` and disabled feature), account
statuses, verify, delete and cursor cases. `FollowerRepositoryTestCase` and
`FollowingRepositoryTestCase` are the shared contracts for the in-memory and
PDO stores and now also cover the timestamped local/remote edges used by the
profile lists; the follower contract also covers local edges, their exclusion
from delivery inboxes and their follower counts. `FavouriteRepositoryTestCase` covers pair
uniqueness, ownership, batch counts and removal. `MastodonCompatibilityTest`
covers prefix validation, scope matching and route metadata, including the
three relationship routes, the three mute routes, the nine list routes, the
four notification routes and search. The post
repository contract still covers the id-aware cursor used by the API, and now
also the `PostSearchRepository` match, visibility, author-filter and offset
contract for both the in-memory and PDO implementations; the in-memory
actor-directory search is covered directly.

## What remains external

Turning this into a complete Mastodon server is intentionally out of scope:

- OAuth authorization, token issuance/refresh/revocation and app registration
  belong to the host or a dedicated identity service.
- Remote statuses, public/remote timelines, favourite federation (outbound
  `Like`/`Undo` and remote counts), remote boost federation, remote
  notifications, streaming and remote search need remote data the core does
  not fetch for this surface. Local boosts/reblogs, private bookmarks, local
  mutes, private lists, notification listing and local status/actor search are
  done above.
- Notification dismissal/unread state and the narrower local account/profile
  endpoints are done above. Instance metadata and statistics are done above.
  Remote profile fetching, remote relationship APIs, remote polls (fetching,
  voting or federating a `Question`), domain blocks that would need a remote
  moderation feed, and remote search remain external; the local
  follow/unfollow, relationship, profile, follower/following list,
  bookmark, mute, list, notification, search, poll-creation/voting and
  instance views above are done.
- Following a remote actor through the compatibility prefix is not part of
  this slice. The public profile lists project remote follow edges that are
  already stored locally, but they never fetch a remote collection; moderation
  and admin APIs are also external. The core `/api/v1/following` path remains
  the federation route.
- The host must map bearer tokens to `userId`/`scopes`, enforce the declared
  rate limits and decide whether to expose the custom prefix publicly.

These are tracked as the compatibility remainder (M6) in the
[roadmap](../roadmap.md); the local routes, mapping, validation, pagination,
idempotency and error behavior above are complete and tested.
