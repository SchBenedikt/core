# Follow graph

The follow graph has two directions. The incoming graph answers **where do
this account's posts have to be delivered?** It stores remote actors that
follow a local account, together with the inbox to use. The outgoing graph
answers **whom does this account follow?** It is a separate table, because the
directions mean different things.

## Model

```php
final readonly class Follower
{
    public string $id;             // local id, used by the API
    public string $localUserId;    // whose follower this is
    public string $actorId;        // absolute ActivityPub actor URL
    public string $inboxUrl;       // absolute individual inbox URL
    public ?string $sharedInboxUrl; // server-wide inbox, when advertised
    public DateTimeImmutable $followedAt;
}
```

`(localUserId, actorId)` is unique. A repeated Follow therefore updates the
inbox instead of creating a second row — a peer that retries cannot cause
duplicate deliveries. `deliveryInboxUrl()` returns the shared inbox when it
is present and the individual inbox otherwise.

The outgoing edge has the same identity and inbox, but points the other way:

```php
final readonly class FollowedActor
{
    public string $id;             // local id, used by the API
    public string $localUserId;    // whose follow this is
    public string $actorId;        // absolute ActivityPub actor URL
    public string $inboxUrl;       // where Follow and Undo go
    public DateTimeImmutable $followedAt;
}
```

The table is `social_follower`; its definition lives in `FollowerSchema` and is
shared by both hosts:

```
id            VARCHAR(64)  primary key
local_user_id VARCHAR(255)
actor_id      VARCHAR(255) unique with local_user_id
inbox_url        VARCHAR(512)
shared_inbox_url VARCHAR(512)  nullable
followed_at      VARCHAR(20)  canonical UTC timestamp
```

The 255-character cap on `actor_id` is deliberate: with utf8mb4 it keeps the
unique index inside MySQL's key limit. The service rejects a longer URL with
422 before the database has to. `shared_inbox_url` is nullable so existing
rows need no backfill; see [shared inboxes](shared-inboxes.md) for the
migration and fallback rules.

## Endpoints

| Method | Path | Meaning |
| --- | --- | --- |
| `GET` | `/api/v1/followers?limit=&before=` | Followers of the caller, newest first, plus `total` |
| `POST` | `/api/v1/followers` | Record a follower; `201` when created, `200` when it already existed |
| `DELETE` | `/api/v1/followers/{id}` | Forget a follower; `204`, or `404` when it is not the caller's |
| `POST` | `/api/v1/following` | Follow a remote actor and deliver a signed `Follow`; `201` or `200` |
| `DELETE` | `/api/v1/following/{id}` | Deliver an `Undo`; `204`, or `404` when it is not the caller's |

The `POST` body is `{"actor": "https://remote.example/users/bob", "inbox":
"https://remote.example/users/bob/inbox", "shared_inbox":
"https://remote.example/inbox/shared"}`. `shared_inbox` is optional and may be
omitted or `null`; when present it must be an absolute http(s) URL of at most
512 characters. `actor` and `inbox` are required and follow the same rules.
Anything invalid is a 422 naming the field. The response keeps its established
`id`/`actor`/`inbox`/`followed_at` shape; the stored shared inbox is used for
delivery, not echoed. The following `POST` uses the same body shape without a
shared inbox, stores the outgoing edge and delivers a `Follow` instead of
recording a follower.

Deleting is scoped to the caller: the id alone must not let one account remove
another account's follower, and the answer is 404 rather than 403 so it does
not reveal that the id exists.

## Delivery

`FollowerInboxResolver` implements `InboxResolver` on top of the repository.
`PostService` already asks the resolver for the author's inboxes when it writes
or deletes a post, so switching the resolver on is all it takes to make
federation fan out:

```
follow (bob)  ──► social_follower
post (alice)  ──► inboxesFor("alice") ──► one delivery job per inbox
```

Inbox URLs are de-duplicated after choosing `shared_inbox_url` when it is set:
two actors on the same server with the same advertised shared inbox receive
one request per post, not two identical ones. A follower without a shared
inbox still receives at its individual inbox. Follow, Undo and Accept always
target the individual inbox. The target is chosen when the job is written; a
failed shared delivery is retried as it is rather than silently switched to
the individual inbox. See [shared inboxes](shared-inboxes.md).

## How edges arrive

Two paths write the graph:

1. the local API (`POST /api/v1/followers`), for a host that has already
   resolved and trusted an actor document;
2. inbound federation: a signed `Follow` on `POST /inbox` creates the edge and
   queues the `Accept`; a signed `Undo(Follow)` removes it. See
   [inbound federation](inbound-federation.md).

Either way the actor and its inboxes come from the actor document, never from
an unauthenticated request body. The shared inbox is read from
`endpoints.sharedInbox` and stored only when it is a valid http(s) URL; a
missing or malformed value leaves the individual inbox in place.

The outgoing graph is written separately by `POST /api/v1/following`, which
records whom a local account follows and delivers the matching `Follow`; no
inbound activity can change it.

## Following graph

`social_following` stores the outgoing edge:

```
id            VARCHAR(64)  primary key
local_user_id VARCHAR(255)
actor_id      VARCHAR(255) unique with local_user_id
inbox_url     VARCHAR(512)
followed_at   VARCHAR(20)  canonical UTC timestamp
```

The inbox is stored because the `Follow` and its later `Undo` have to be
delivered there. It is separate from the follower inbox: one receives posts,
the other receives follow activities. `FollowingService::follow()` inserts the
edge and sends a signed `Follow`; `unfollowById()` sends the `Undo` first and
removes the edge only after the peer accepted it. A refused `Follow` is rolled
back, a refused `Undo` leaves the edge retryable. See the
[following collection](following-collection.md).

`FollowingRepository::actorIdsPage()` feeds the public collection; the root
count comes from `count()`. `HomeTimelineRepository` also reads `actorIds()` to
include remote posts and boosts from the actors the reader follows. The
inbound inbox uses `localUserIdsForActor()` as the reverse check: a remote
`Create`/`Delete`/`Announce` is accepted only when at least one local account
follows its actor.

## Blocks

A per-user block is stored in `social_block` (`BlockSchema`) and is separate
from both follow directions. `BlockRepository::actorIds()` returns the actors
the reader blocks; the home timeline excludes those remote rows for that reader
only. The outgoing edge is not removed, and another local account that follows
the same actor still sees its posts. See [blocks](blocks.md).

The optional instance-wide `BlockedActorPolicy` is a different seam: it
refuses inbound activities before they are stored. It is not the per-user block
list.

## Follow policy

A verified Follow is not automatically accepted. The optional `FollowPolicy`
receives the resolved `RemoteActor` and the local user id and can refuse the
edge; `AcceptAllFollows` is the default. A refusal writes nothing, answers
`403 follow_not_accepted` and releases the activity claim, so the peer's retry
can succeed after the host changes its policy. See
[inbound federation](inbound-federation.md).

## What is missing

- local-to-local follow edges and a local-follow home timeline.

The follower list is published at `/@{handle}/followers`; see the
[followers collection](followers-collection.md).
