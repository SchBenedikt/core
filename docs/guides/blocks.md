# Blocks

A block is a private decision by one local account to hide a remote actor's
posts and boosts from its own home timeline. It is not a server-wide
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
- It does not send an ActivityPub `Block` to the peer. The remote server is not
  told, and inbound activities are still stored for other local followers.

The home-timeline filter is applied inside the combined repository, before the
page limit. A block therefore cannot make a page short or make the cursor skip
an entry: the local and remote halves are filtered and sorted as one query.

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
and any boost of its content by a non-blocked actor.

## Storage

The table is `social_block`, defined by
[`BlockSchema`](../../src/Domain/Federation/BlockSchema.php):

| Column | Meaning |
| --- | --- |
| `id` | local row id, used by `DELETE` |
| `local_user_id` | the account whose timeline is filtered |
| `actor_id` | the remote actor URL |
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
- the policy affects whether `POST /inbox` accepts a `Create`, `Delete` or
  `Announce` at all;
- the core has no per-user policy that rejects shared inbound content, because
  one reader's block must not erase content another reader follows.

A host may provide both; they answer different questions.

## What is not implemented

- blocking local accounts or hiding local posts from a local-to-local follow
  graph;
- sending an ActivityPub `Block`/`Undo(Block)` to the remote server;
- a public block collection;
- server-wide defederation management beyond the optional policy seam;
- backfilling a block list into the home timeline (the filter is evaluated on
  every read).
