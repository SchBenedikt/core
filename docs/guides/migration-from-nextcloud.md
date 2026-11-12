# Migrating a Nextcloud installation

This guide is for the `social` host repository. The core does not read the
Nextcloud database and has no migration runner; the host owns the export,
import and cutover. The rules behind the steps are in
[ADR 0004](../adr/0004-incremental-migration-and-ownership.md).

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

If an existing actor URL has a prefix, configure the same prefix. For example,
a Nextcloud installation mounted under `/apps/social/federation` uses:

```
social/instance.url = https://cloud.example/apps/social/federation
web server prefix  = /apps/social/federation
```

The host strips the prefix before calling the kernel; the actor URL and
WebFinger `self` link keep the prefix. A root rewrite is also valid, provided
all four strings are unchanged.

A mismatch is a stop condition. Do not invent a new actor URL, key id or post
id to make the import fit. Keep that account on the old stack until the host
can serve the old identity, or plan a separate key rotation with an overlap
window.

## Phase 1 — Stage the target tables

Create the core tables with the schema classes from the host's migration:

- `social_post` — `PostSchema`
- `social_follower` — `FollowerSchema`
- `social_following` — `FollowingSchema`
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

Run these against the staging database before enabling any route:

- source and target row counts per table;
- a deterministic checksum over `(id, author_id, published_at, deleted_at)`
  for posts and over the actor/account pair for follow edges;
- null/duplicate checks for every required column;
- canonical timestamp checks (no timezone suffix other than `Z`, no
  sub-second values);
- actor URL samples derived through `ActivityFactory`, compared with the
  baseline;
- key-id samples compared with the baseline;
- `social_following.id` samples compared with the old Follow activity ids.

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
4. **Inbound inbox.** Enable `POST /inbox` only with the durable activity log,
   actor resolver and preserved key. Start with a test domain. A replay and a
   wrong signature must be refused, not logged and ignored.
5. **UI.** Move one screen at a time through the shared UI bridge described in
   [the shared UI guide](shared-ui.md); do not move the backend and the screen
   in one flag flip.

Do not migrate AT Protocol, media processing or inbound `Create`/`Delete`
until those features exist in the core. Keep the old implementation for them.
A core feature that does not exist cannot be replaced by a host-specific
branch in `src/`.

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
- AT Protocol data migration before the core implements the protocol.
