# ADR 0004 — Migrate feature by feature, with one owner

- Status: accepted
- Date: 2026-11-12

## Context

The Nextcloud app is already a working product. It has users, posts, a follow
graph, inbox history, AT Protocol code, media handling and a Vue front end. The
core currently implements a smaller slice of that behaviour. Replacing the app
in one step would put working federation identities and user data at risk.

At the same time, three repositories must not become three copies of the same
application. If a Nextcloud-specific workaround, a standalone installer or an
unrelated media experiment lands in `src/`, the core stops being host-neutral
and both hosts inherit the cost.

The migration therefore has two parts that must be decided together:

1. how existing data and federation identities move into the core; and
2. which repository owns a new feature.

## Decision

### 1. Migrate with a strangler, never a big-bang cutover

The old implementation stays authoritative until a feature (and, where
necessary, one account or one domain) passes its migration gates. The core is
introduced in shadow/read-only mode first. Write traffic is moved one slice at
a time behind a feature flag.

A feature is not migrated just because the endpoint exists in both stacks.
It is migrated when the import, the identity checks, the contract tests and a
real peer smoke test have passed.

There is **no migration runner in this repository**. The host knows its source
schema and its deployment; the core only owns the target schemas, the protocol
factories and the contracts.

### 2. Existing federation identity is immutable

A migration must not silently generate a new public identity. The following
values have to stay byte-for-byte identical for every migrated account:

| Value | Why it must not change |
| --- | --- |
| Actor URL | Peers, followers and cached actor documents already point at it |
| Key id | Peers verify signatures and may have cached the old key document |
| Note/object URL | It is the ActivityPub object id and the target of `Create` and `Delete` |
| Create/Delete activity ids | Receivers use them for idempotency |
| Follow activity id | A later `Undo` has to match the `Follow` the peer accepted |

The core derives these strings from configuration and stored ids:

- `ActivityFactory::actorId()` = `{instance.url}/@{urlencoded user id}`;
- `ActivityFactory::keyId()` = `{actor}#main-key`;
- `ActivityFactory::noteId()` = `{instance.url}/api/v1/posts/{post id}`;
- `ActivityFactory::followId()` = `{instance.url}/api/v1/following/{followed row id}/follow`.

Before the import is accepted, the host must set `social/instance.url` and its
route prefix so those functions reproduce the existing values exactly. If that
is impossible, the account must stay on the old stack until a compatibility
solution exists. A new actor URL is not an acceptable shortcut.

For a prefixed Nextcloud installation this usually means:

```
instance.url = https://cloud.example/apps/social/federation
strip prefix = /apps/social/federation
actor URL    = https://cloud.example/apps/social/federation/@alice
```

A root rewrite works too, but the same rule applies: the URL in the actor
document, the URL the web server serves and the URL WebFinger advertises must
be the same string.

The same care applies to the data behind those URLs:

- Preserve post ids. The core's note and `Create` ids are derived from them,
  and the schema limits the id to 64 characters.
- Preserve each `social_following.id` when importing an outgoing follow. A
  future `Undo` embeds an activity id derived from that row id. Losing it
  means a peer may not match the `Undo` to the original `Follow`.
- Preserve the instance key and the `{actor}#main-key` key id during cutover.
  Rotate keys only as a separate, planned operation with an overlap window.
- Do not import an old process-local replay guard. Start the durable
  `PdoActivityLog` at cutover and turn the old inbox endpoint off for the
  migrated account; two active endpoints would be two replay domains.

### 3. Data import is host-owned

The core has no user table and must not learn Nextcloud's database. The host
exports and imports its own users, keys, posts and follow edges into the
documented core tables. An import must be:

- idempotent, so a retried deployment does not duplicate rows;
- transactional per batch;
- checked against source counts and a deterministic checksum;
- timestamp-normalized through the core's canonical UTC second format; and
- run without deleting or rewriting the legacy tables.

The host maps its local account id to `local_user_id` / `author_id`. That id
is the same id used to build the actor URL. It must not be replaced by a new
core-generated user id.

Queue jobs and the replay log are **not** copied blindly. Legacy job payloads
have a different shape and a different handler set. Drain or stop the old
queue at cutover, start the core queue, and do not send the same delivery from
both stacks.

### 4. One owner per feature

| Concern | Owner | Rule |
| --- | --- | --- |
| Posts, timelines, follow graph, ActivityPub, AT Protocol, media domain rules, route contract | `core` | Needed by both hosts, or a protocol rule that must be tested once |
| OCP adapters, Nextcloud controllers, templates, admin settings, Nextcloud auth integration | `social` | Uses Nextcloud APIs or exists only for Nextcloud |
| Bootstrap, accounts/auth for the standalone product, installer, container/systemd, server migrations | `server` | Exists only for the standalone deployment |
| Shared Vue components, composables, `HostBridge` | `@alohasocial/ui` | Both hosts need the same presentation and client contract |

If only one host needs a behaviour, it does not belong in the core. If both
hosts need it, the core owns the rule and each host owns only its adapter. A
host-specific exception must never be hidden as a branch in `src/`.

The shared UI is the fourth artifact, not a fourth backend: it talks to the
core HTTP contract through a host bridge and never imports OCP or a server
runtime. See [ADR 0005](0005-shared-ui.md).

### 5. Cutover gates

A slice may switch traffic only when all of these are true:

1. the host's import dry run reports the same counts as the source;
2. the read-only `CutoverValidator` reports no violations for actor URLs, key
   ids, note URLs, Create/Delete/Follow/Undo activity ids and the preserved
   outgoing follow row ids;
3. the core's route manifest and the host's registered routes agree;
4. the core contract tests, the host adapter tests and the relevant
   `ApplicationHostTest` pass;
5. WebFinger resolves to the actor document, the key id verifies a real signed
   request, and the outbox is readable by a peer;
6. a rollback flag can return the account to the old read/write path without
   restoring a backup; and
7. only one stack is allowed to own writes for that account.

The migration order is:

1. posts and timelines (import, read-only parity, then writes);
2. actor document, WebFinger, followers and following collections;
3. outbound `Follow`/`Undo` and the old queue drain;
4. `POST /inbox` with the durable activity log;
5. the UI slice using `@alohasocial/ui`;
6. AT Protocol, media processing and inbound `Create`/`Delete` only after the
   core implements those features; the old implementation remains in place
   until then.

## Consequences

**Good**

- Existing followers keep working because the public identity never changes.
- A failed slice can be rolled back per account instead of restoring a whole
  installation.
- New features have an obvious home; the core remains a library.

**Costs**

- Migration is slower than copying tables, and some accounts may stay on the
  old stack longer.
- The host must preserve and verify ids that look internal, especially the
  outgoing follow row id.
- A feature that both products need still requires two host adapters and a
  shared UI bridge; that is the intended boundary, not a failure.

**Rejected alternatives**

- **Big-bang replacement.** It changes identities and makes rollback a restore
  operation.
- **A compatibility shim in the core.** It turns Nextcloud's schema and URL
  conventions into core architecture.
- **A second copy of the Vue components in each host.** It guarantees drift and
  makes a cross-host fix a two-repository change.
- **Putting host-specific features in `src/` "for now".** There is no later
  cleanup budget for a boundary that was never enforced.

## Follow-ups

- Run the concrete Nextcloud import from
  [the migration guide](../guides/migration-from-nextcloud.md) in a staging
  database and record the `bin/validate-cutover` parity report.
- Extract the Vue front end under `@alohasocial/ui` with the bridge contract in
  [the shared UI guide](../guides/shared-ui.md).
- Add a host parity test that compares registered routes with
  `bin/export-routes` in both host repositories.
