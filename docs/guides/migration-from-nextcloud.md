# Migrating a Nextcloud installation

This guide is for the `social` host repository. The core does not read the
Nextcloud database and has no migration runner; the host owns the export,
import and cutover. The rules behind the steps are in
[ADR 0004](../adr/0004-incremental-migration-and-ownership.md).

The core does ship a read-only cutover validator: `CutoverValidator`, exposed
as `bin/validate-cutover` and `composer migration:validate`. It reads the
neutral JSON export described below, derives the core identities and writes a
parity report. It never connects to Nextcloud and contains no host table names.

The migration is a strangler migration: the old endpoints stay live until one
feature has passed its checks. Never import into production and point every
route at the core in the same deployment.

## Before touching data

Record a baseline from the running app:

- every local handle and its exact actor URL;
- every key id and the public key that peers currently verify;
- a sample of public post URLs and their `Create`/`Delete` activity ids;
- every outgoing follow row id (the core derives a future `Undo` id from it);
- counts for users, posts, followers, following and pending delivery jobs;
- the current `social/instance.url` value and the route prefix.

Save the baseline outside the migration transaction. It is the input to the
parity report, not something to reconstruct from the target database after a
failure. `php bin/export-routes` gives the route side of the same report.

### The identity gate

The core derives actor, key, note and follow ids with `ActivityFactory`. For
every migrated account, the following must match the baseline exactly:

| Check | Core derivation |
| --- | --- |
| Actor URL | `{instance.url}/@{urlencoded local user id}` |
| Key id | `{actor}#main-key` |
| Post URL | `{instance.url}/api/v1/posts/{urlencoded post id}` |
| Follow activity id | `{instance.url}/api/v1/following/{following row id}/follow` |
| Undo activity id | `{Follow activity id}/undo` |

If an existing actor URL has a prefix, configure the same prefix. For example,
a Nextcloud installation mounted under `/apps/social/federation` uses:

```
social/instance.url = https://cloud.example/apps/social/federation
web server prefix  = /apps/social/federation
```

The host strips the prefix before calling the kernel; the actor URL and
WebFinger `self` link keep the prefix. A root rewrite is also valid, provided
all of these strings are unchanged.

A mismatch is a stop condition. Do not invent a new actor URL, key id or post
id to make the import fit. Keep that account on the old stack until the host
can serve the old identity, or plan a separate key rotation with an overlap
window.

## Export format

The validator's input is a single JSON object with this shape. It is deliberately
neutral: `following` means the exported outgoing-follow rows, not a Nextcloud
table, and the host is responsible for filling it from its import.

```json
{
  "format": "alohasocial.migration-cutover",
  "version": 1,
  "instance_url": "https://social.example",
  "accounts": [{
    "legacy_user_id": "alice",
    "local_user_id": "alice",
    "actor_url": "https://social.example/@alice",
    "key_id": "https://social.example/@alice#main-key"
  }],
  "posts": [{
    "legacy_id": "post-1",
    "id": "post-1",
    "author_id": "alice",
    "content": "Hello",
    "content_warning": null,
    "visibility": "public",
    "published_at": "2026-11-11T10:00:00Z",
    "deleted_at": null,
    "note_url": "https://social.example/api/v1/posts/post-1",
    "create_id": "https://social.example/api/v1/posts/post-1/activity",
    "delete_id": "https://social.example/api/v1/posts/post-1/delete"
  }],
  "followers": [{
    "legacy_id": "follower-1",
    "id": "follower-1",
    "local_user_id": "alice",
    "actor_id": "https://remote.example/users/bob",
    "inbox_url": "https://remote.example/users/bob/inbox",
    "followed_at": "2026-11-11T10:00:00Z"
  }],
  "following": [{
    "legacy_id": "follow-1",
    "id": "follow-1",
    "local_user_id": "alice",
    "actor_id": "https://remote.example/users/bob",
    "inbox_url": "https://remote.example/users/bob/inbox",
    "followed_at": "2026-11-11T10:00:00Z",
    "follow_id": "https://social.example/api/v1/following/follow-1/follow",
    "undo_id": "https://social.example/api/v1/following/follow-1/follow/undo"
  }],
  "queue": [{
    "id": "job-1",
    "type": "federation.deliver",
    "activity_type": "Create",
    "activity_id": "https://social.example/api/v1/posts/post-1/activity",
    "inbox": "https://remote.example/users/bob/inbox",
    "key_id": "https://social.example/@alice#main-key",
    "available_at": "2026-11-11T10:00:00Z",
    "attempts": 0,
    "dead_at": null
  }],
  "activity_log": [{
    "activity_id": "https://remote.example/activities/1",
    "seen_at": "2026-11-11T10:00:00Z"
  }]
}
```

All arrays are required; they may be empty. A missing array is a schema error,
not an empty import. The fields mean:

| Record | Field | Meaning |
| --- | --- | --- |
| top level | `format`, `version` | Must be `alohasocial.migration-cutover` and `1` |
| top level | `instance_url` | The `social/instance.url` the core will use; no trailing slash |
| `accounts` | `legacy_user_id`, `local_user_id` | Old and new local user id; both must be identical |
| `accounts` | `actor_url`, `key_id` | Baseline values the validator compares with the derived actor and key id |
| `posts` | `legacy_id`, `id` | Old and imported post id; both must be identical |
| `posts` | `author_id` | An exported `local_user_id` |
| `posts` | `content`, `content_warning`, `visibility` | Values used to construct the post for identity derivation |
| `posts` | `published_at`, `deleted_at` | Canonical UTC seconds; `deleted_at` is `null` for a live post |
| `posts` | `note_url`, `create_id`, `delete_id` | Baseline object and activity ids |
| `followers` | `legacy_id`, `id`, `local_user_id` | Old/new row id and the owning exported account; the follower row id is internal and may change |
| `followers` | `actor_id`, `inbox_url`, `followed_at` | Remote actor values; copy them exactly |
| `following` | `legacy_id`, `id`, `local_user_id` | **`id` is the preserved `social_following.id`** |
| `following` | `actor_id`, `inbox_url`, `followed_at` | Remote actor values; copy them exactly |
| `following` | `follow_id`, `undo_id` | Baseline Follow and Undo activity ids |
| `queue` | `id`, `type`, `activity_type` | Delivery job id and type; the core accepts `federation.deliver` |
| `queue` | `activity_id`, `inbox`, `key_id` | The delivered activity, target inbox and an exported account key id |
| `queue` | `available_at`, `attempts`, `dead_at` | Canonical UTC seconds, a non-negative integer and an optional UTC dead-letter time |
| `activity_log` | `activity_id`, `seen_at` | The replay-guard id and its canonical UTC time |

The `actor_url`, `key_id`, `note_url`, `create_id`, `delete_id`, `follow_id` and
`undo_id` fields are the **pre-cutover baseline values**. Do not regenerate
those fields from the imported rows before exporting: that would compare the
new identity with itself. The validator derives the expected values from
`instance_url`, `local_user_id`, `id` and the post fields, then compares them
with the baseline values in the export.

`following.id` is the field that carries `social_following.id`. The validator
derives both the Follow and Undo ids from it. A changed row id is reported at
`following[n].id` even when the host also regenerated both activity URLs, so
the exporter must provide both the old id (`legacy_id`) and the imported id
(`id`).

## Running the validator

Run the validator against the staging export before the shadow phase and again
after the final import:

```bash
php bin/validate-cutover var/migration-export.json
php bin/validate-cutover --json var/migration-export.json
composer migration:validate -- var/migration-export.json
```

Exit codes are stable:

| Code | Meaning |
| --- | --- |
| `0` | Every exported identity and reference is consistent; parity passed |
| `1` | At least one parity violation; the report lists every one |
| `2` | The file is unreadable, is not valid JSON, or fails the export schema |

The plain report starts with `Result: PASS` or `Result: FAIL (n violation(s))`.
Each violation has a `record` (for example `following[0]`), a `field` (for
example `undo_id`), a message, the expected value and the actual value. `--json`
returns the same data as machine-readable JSON with `source`, `passed`,
`exit_code` and `violations`.

The validator checks the export, not the source database. Source/target counts
and the deterministic checksum below are still host-side preconditions; the
validator cannot derive them from target rows alone. It does check required
fields, duplicate ids and pairs, canonical timestamps, URL shape, exported
account references, queue key references and queue activity references.

## Phase 1 — Stage the target tables

Create the core tables with the schema classes from the host's migration:

- `social_post` — `PostSchema`
- `social_follower` — `FollowerSchema`
- `social_following` — `FollowingSchema`
- `social_remote_post` — `RemotePostSchema` (new remote content; no legacy import)
- `social_remote_thread_post` — `RemoteThreadSchema` (on-demand context cache; no legacy import; a host may start empty)
- `social_block` — `BlockSchema` (per-user blocks; no legacy import)
- `social_job` — `JobSchema`
- `social_activity_log` — `ActivityLogSchema`

Use the host's schema API for Nextcloud; do not copy a second column list from
this guide. The classes are the source of truth.

The core has no user table. A local account remains the host's user id; that
id is written to `author_id` and `local_user_id` and is used to build the
actor URL. Do not replace UIDs with generated values.

### Import mapping

| Target | Source / value | Invariant |
| --- | --- | --- |
| `social_post.id` | Legacy post id | Keep it; the object id is derived from it, and it must fit `VARCHAR(64)` |
| `social_post.author_id` | Legacy local user id | The same id used in the actor URL |
| `social_post.content` | Legacy content | Preserve as text; do not render HTML during import |
| `social_post.content_warning` | Legacy summary/spoiler | Preserve or null; never invent a warning |
| `social_post.visibility` | Legacy visibility | Map only to the four core values; reject unknown values |
| `social_post.published_at` | Legacy publication time | Normalize to UTC `YYYY-MM-DDTHH:MM:SSZ`; keep the second |
| `social_post.deleted_at` | Legacy tombstone/deletion time | Keep null when the post is live |
| `social_follower.id` | Legacy follower row id, or a stable new id | Internal id; preserve when clients may refer to it |
| `social_follower.local_user_id` | Legacy account id | Must match the actor owner |
| `social_follower.actor_id` | Remote actor URL | Copy exactly; never normalize the remote host; must fit 255 characters |
| `social_follower.inbox_url` | Stored inbox | Prefer the latest stored inbox; validate scheme/host and the 512-character limit |
| `social_follower.shared_inbox_url` | Actor's `endpoints.sharedInbox`, if known | Nullable; validate scheme/host and the 512-character limit. `NULL` falls back to `inbox_url`, so this needs no backfill |
| `social_follower.followed_at` | Original follow time | Canonical UTC second format |
| `social_following.id` | **Legacy outgoing follow row id** | **Preserve it**; a future `Undo` embeds an id derived from it |
| `social_following.local_user_id` | Legacy account id | Must match the actor owner |
| `social_following.actor_id` | Remote actor URL | Copy exactly; must fit 255 characters |
| `social_following.inbox_url` | Stored inbox | Validate before using it for a signed Follow/Undo; must fit 512 characters |
| `social_following.followed_at` | Original follow time | Canonical UTC second format |

Do not copy legacy queue rows into `social_job`. Their payloads and handler
types belong to the old application. Drain the old queue before cutover, or
stop it and explicitly re-enqueue only jobs whose payload matches the core's
`FederationDeliveryHandler` contract.

Do not import the old replay guard. Start `PdoActivityLog` empty at cutover and
disable the old inbox for the migrated account. A replay guard shared by two
active endpoints is not a replay guard.

### Import checks

The authoritative identity check is the read-only validator in
[Export format](#export-format) and [Running the validator](#running-the-validator).
Export the staging rows in that format and run it before enabling any route.

These checks still belong to the host because the neutral export does not
contain the source rows:

- source and target row counts per table;
- a deterministic checksum over `(id, author_id, published_at, deleted_at)`
  for posts and over the actor/account pair for follow edges;
- null/duplicate checks for source columns that the target schema does not
  enforce;
- the staging import's idempotency run.

The validator covers the actor URL, key id, note URL, Create/Delete/Follow/Undo
activity ids and the preserved `social_following.id` record by record. Do not
replace those checks with an ad-hoc sample that only compares regenerated
values with each other.

The import must be idempotent. A second run should update nothing or be
rejected, not duplicate rows. Wrap each batch in a transaction; a failed batch
must leave the target at its previous checkpoint.

Do not truncate a URL, post id or body to fit a column. The core's service
limits are part of its public behaviour; if legacy content or an actor URL
cannot fit, record it as an exception and decide the product policy before
cutover instead of silently changing it.

## Phase 2 — Read-only shadow

Boot `Application::forHost()` with the imported database and a fake or real
HTTP client, but do not register the public core routes yet. Call the services
directly or mount the routes on an internal host:

1. read a known public post and compare its `id`, author and timestamps;
2. read a timeline and compare the imported count;
3. read followers/following for one account and compare actor ids and order;
4. render the actor document, WebFinger and outbox and compare every URL with
   the baseline.

This catches an identity error before a peer sees it. A mismatch in a URL is a
blocker, not a cosmetic bug.

## Phase 3 — Cut over one slice

Use a feature flag scoped to one account or one test domain. The old and new
stacks must not both own writes for the same handle.

1. **Posts and timelines.** Enable core reads, then writes, for the account.
   Keep the old queue disabled for that account.
2. **Actor surface.** Serve the core actor document, WebFinger, followers,
   following and outbox. Confirm that a real peer resolves WebFinger to the
   same document and verifies a signed request with the preserved key.
3. **Outgoing follows.** Enable `POST`/`DELETE /api/v1/following`. Because the
   follow row id was preserved, a later Undo still refers to the Follow the
   peer accepted. Drain the old queue before this step.
4. **Inbound inbox.** Enable `POST /inbox` and `POST /inbox/shared` only with the durable activity log,
   actor resolver and preserved key. Start with a test domain. A replay and a
   wrong signature must be refused, not logged and ignored. Once the
   `social_remote_post` table exists, signed `Create`/`Delete`/`Announce` from
   followed actors can be enabled; remote content is stored once per activity
   and read through the follow graph.
5. **UI.** Move one screen at a time through the shared UI bridge described in
   [the shared UI guide](shared-ui.md); do not move the backend and the screen
   in one flag flip.

The core now owns the public text-only AT Protocol publishing foundation, but
not account linking, OAuth/session storage or the full repository/AppView
stack. Do not migrate those account credentials or the old AT Protocol stack
until the host adapter is ready; publication rows in
`social_atproto_publication` are new core rows, not a copy of the old app's
data. Remote-post backfill, thread/context fetching and media processing now
exist in the core, so migrate those only through the normal cutover phases. A
core feature that does not exist cannot be replaced by a host-specific branch
in `src/`. See [AT Protocol publishing](atproto.md).

## Phase 4 — Close the old path

Only after the agreed observation window:

- remove the old route registration for migrated accounts;
- stop the old queue and its worker for those accounts;
- keep the old tables read-only until the rollback window expires;
- record the final counts and the actor/key URL baseline in the migration log;
- keep a per-account rollback path until the next release, not just a
  database backup.

## Rollback

The old tables are never mutated by the import. A rollback is a feature-flag
change back to the old read/write path plus a drain of any core queue jobs,
not a restore of the whole database. This is why the import is additive and
why the old endpoints stay available during the observation window.

A key rotation is not a rollback. If the key changes, peers need an overlap
period and the migration must be treated as a separate federation event.

## Out of scope

- Reading the Nextcloud database from the core.
- A universal migration runner in this repository.
- Replacing the Nextcloud admin UI, auth or media jobs during the core
  migration.
- AT Protocol account/OAuth credential migration and the full repository/AppView stack before their host adapter exists.
