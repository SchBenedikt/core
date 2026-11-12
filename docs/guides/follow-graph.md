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
    public string $inboxUrl;       // absolute inbox URL
    public DateTimeImmutable $followedAt;
}
```

`(localUserId, actorId)` is unique. A repeated Follow therefore updates the
inbox instead of creating a second row — a peer that retries cannot cause
duplicate deliveries.

The table is `social_follower`; its definition lives in `FollowerSchema` and is
shared by both hosts:

```
id            VARCHAR(64)  primary key
local_user_id VARCHAR(255)
actor_id      VARCHAR(255) unique with local_user_id
inbox_url     VARCHAR(512)
followed_at   VARCHAR(20)  canonical UTC timestamp
```

The 255-character cap on `actor_id` is deliberate: with utf8mb4 it keeps the
unique index inside MySQL's key limit. The service rejects a longer URL with
422 before the database has to.

## Endpoints

| Method | Path | Meaning |
| --- | --- | --- |
| `GET` | `/api/v1/followers?limit=&before=` | Followers of the caller, newest first, plus `total` |
| `POST` | `/api/v1/followers` | Record a follower; `201` when created, `200` when it already existed |
| `DELETE` | `/api/v1/followers/{id}` | Forget a follower; `204`, or `404` when it is not the caller's |

The `POST` body is `{"actor": "https://remote.example/users/bob", "inbox":
"https://remote.example/users/bob/inbox"}`. Both must be absolute http(s) URLs;
anything else is a 422 naming the field.

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

Inbox URLs are de-duplicated: two actors that share an inbox receive one
request per post, not two identical ones.

## How edges arrive

Two paths write the graph:

1. the local API (`POST /api/v1/followers`), for a host that has already
   resolved and trusted an actor document;
2. inbound federation: a signed `Follow` on `POST /inbox` creates the edge and
   queues the `Accept`; a signed `Undo(Follow)` removes it. See
   [inbound federation](inbound-federation.md).

Either way the actor and its inbox come from the actor document, never from an
unauthenticated request body.

The outgoing graph is written separately: `FollowingService::follow()`
records whom a local account follows, and no inbound activity can change it.

## Following graph

`social_following` stores the outgoing edge:

```
id            VARCHAR(64)  primary key
local_user_id VARCHAR(255)
actor_id      VARCHAR(255) unique with local_user_id
followed_at   VARCHAR(20)  canonical UTC timestamp
```

There is no inbox column: this graph is for reading who the account follows,
not for delivery. `FollowingService::follow()` and `unfollow()` write it, and
`FollowingRepository::actorIds()` feeds the public
[following collection](following-collection.md). A local follow HTTP route and
the outbound `Follow` activity are still missing.

## What is missing

- a local follow HTTP route and outbound Follow delivery; the following
  collection is read-only for now;
- a follow-based home timeline that reads the outgoing graph;
- a policy for approving Follows instead of accepting every valid one;
- paging for very large follower and following collections.

The follower list is published at `/@{handle}/followers`; see the
[followers collection](followers-collection.md).
