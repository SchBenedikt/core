# Blocks

A block is a private decision by one local account to hide an actor's posts
and boosts from its own home timeline. The actor may be remote or local; both
are stored as the same absolute actor URL. A block is not a server-wide
defederation rule and it does not alter another local account's follow graph.

## What a block does

- It hides remote posts and boosts whose signing actor is blocked from the
  caller's `GET /api/v1/timelines/home`.
- It also hides a boost when the boosted object's author is blocked, even if
  the local account has not blocked the booster. The filter checks both the
  signing actor and `object_author_id`.
- It does not delete the shared `social_remote_post` rows. Another local
  account that follows the same actor still sees them.
- It does not remove an outgoing follow edge. The edge keeps the actor in the
  public following collection; the reader simply stops seeing its content.
- It has no effect on the public timeline, because that timeline only contains
  local posts.
- For a **remote** actor, it queues one signed ActivityPub `Block` to the
  actor's individual inbox; `DELETE` queues `Undo(Block)`. The local block is
  committed first, so a peer that is offline or publishes no inbox cannot make
  the block or its removal fail. The queue retries like any other delivery.
- For a followed **local** account, it omits that account's local posts from
  the caller's home timeline. The follow edge stays in place; unblocking makes
  the posts visible again. The local actor URL is derived by the host from the
  local account id (for example `https://social.example/@bob`). Local blocks
  send no ActivityPub activity; the relationship is already local.
- Inbound activities from other local followers are still stored; one
  reader's block does not erase shared content.

The remote home-timeline filter is applied inside the combined repository,
before the page limit. The local half is filtered in the local-follow lookup
before its page is merged. A block therefore cannot make a page short or make
the cursor skip an entry: both halves are filtered and sorted as one query.

## API

The routes are authenticated and act on the caller's own list.

```http
POST /api/v1/blocks
Content-Type: application/json

{"actor": "https://remote.example/users/bob"}
```

`201` with `Location: /api/v1/blocks/{id}` when the block is new, `200` with
the existing row when the actor is already blocked:

```json
{"block": {"id": "…", "actor": "https://remote.example/users/bob",
  "blocked_at": "2026-11-11T10:00:00Z"}}
```

```http
GET /api/v1/blocks?limit=20&before=2026-11-11T10:00:00Z
```

```json
{"blocks": [ … ], "total": 1}
```

`limit` defaults to 20 and is clamped to 1–100. `before` is exclusive and must
be a canonical timestamp; anything else is `400 invalid_cursor`.

```http
DELETE /api/v1/blocks/{id}
```

`204` when the block belongs to the caller, otherwise `404 not_found`. The 404
deliberately does not reveal that another account owns the id.

The actor must be an absolute `http(s)` URL of at most 255 characters. A bare
handle is rejected with `422 validation_failed` because it would not match the
stored ActivityPub actor id. Blocking an actor hides both its own posts/boosts
and any boost of its content by a non-blocked actor. A host that wants to
block a local account uses that account's absolute local actor URL (for
example `https://social.example/@bob`); the same idempotent create/list/delete
routes apply.

## Storage

The table is `social_block`, defined by
[`BlockSchema`](../../src/Domain/Federation/BlockSchema.php):

| Column | Meaning |
| --- | --- |
| `id` | local row id, used by `DELETE` |
| `local_user_id` | the account whose timeline is filtered |
| `actor_id` | the absolute actor URL, remote or local |
| `blocked_at` | canonical UTC timestamp, newest first in the list |

`(local_user_id, actor_id)` is unique. Two accounts may block the same actor;
one account cannot create two rows for it. The PDO home-timeline query uses a
`NOT EXISTS` against this table, scoped to the reader, rather than copying or
deleting remote rows.

## Instance-wide policy is separate

`BlockedActorPolicy` is an optional host seam that refuses **inbound
activities** from an actor for the whole instance. It is not the per-user block
list:

- a per-user block affects only that user's home timeline;
- the policy affects whether `POST /inbox` or `/inbox/shared` accepts a `Create`, `Delete` or
  `Announce` at all;
- the core has no per-user policy that rejects shared inbound content, because
  one reader's block must not erase content another reader follows.

A host may provide both; they answer different questions.

Blocking a local account is local-only: no ActivityPub `Block` is created for
it, and another local account may still follow and see that account. The same
absolute actor URL format is used for both kinds of target.

## Federation details

`BlockService` resolves the remote actor document through
`ActorInboxResolver` and queues `Block` with `to: [actor]`; the activity object
is the actor URL, not the local block row id. `Undo(Block)` wraps the original
`Block` activity. If the actor document is unavailable, has a mismatching
`id`, or has no valid inbox, the local block remains and no job is queued. A
later `DELETE` still removes the local row and attempts its own `Undo`.

## Blocks received from remote actors

Incoming `Block` activities are stored separately in `social_inbound_block`,
keyed by the local account URI and the remote actor. The inbox accepts a Block
only after signature verification and requires its object to resolve to one of
this instance's local actor URIs. Nested actor objects are reduced to their
`id`; a Block for a remote or unknown object is refused. No notification is
sent for a received block.

An accepted Block removes that actor's follower edge for the addressed local
account and the account's outgoing follow to that actor. New Follow requests
are refused while the relationship is active, and the actor cannot add
reactions to that local account's posts. Other local accounts keep their own
relationships and visibility. The relationship row records the Block activity
URI so a nested Undo must match the original actor, target and activity; a
URI-only Undo is looked up by the signing actor and the stored activity URI.
Unknown and already-removed Undo references are successful no-ops.

These checks authenticate the remote actor and protect this instance's local
state; they do not establish whether the remote actor's block decision is
truthful or expose private remote block lists.

## What is not implemented

- a public block collection;
- publishing an inbound `blocked_by` relationship through account APIs;
- filtering remote content globally when one local account receives a Block;
- server-wide defederation management beyond the optional policy seam;
- backfilling a block list into the home timeline (the filter is evaluated on
  every read).
