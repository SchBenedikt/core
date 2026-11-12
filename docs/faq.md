# FAQ

### Is this a server I can install?

No. It is a library. The installable product is the Nextcloud app today and the
future `AlohaSocial/server`; both depend on this package. `php bin/core-demo`
runs it end to end without a server so you can see the behaviour.

### Why not fork the Nextcloud app?

Two copies diverge on the first busy week. A fork means every federation fix is
written twice and tested once. See [ADR 0001](adr/0001-shared-core-two-hosts.md).

### Why PHP?

The behaviour worth keeping is PHP: signatures, ActivityStreams, the delivery
queue. A rewrite would reimplement it and still have to match it byte for byte.
See [ADR 0002](adr/0002-runtime.md).

### Why does `src/` refuse to mention Nextcloud?

A single import of `OCP\` makes the core impossible to run outside Nextcloud.
`composer check:boundary` fails the build instead, because the rule is easier to
keep than to restore.

### Why can't I create a direct post?

A direct post needs recipients. The follow graph tracks who follows *you*, not
who you follow, so there is no audience to deliver to. Refusing with 422 is
honest; delivering to nobody is not.

### Why does a followers-only post answer 404 instead of 403?

A 403 confirms the post exists. For a private post that is already a leak. The
author still sees it because the visibility check includes the viewer.

### Why is there no migrations runner?

The mechanism differs per host: Nextcloud has its own schema API, the standalone
server will use Doctrine Migrations. The core shares the *schema*
(`PostSchema`, `FollowerSchema`), not the runner.

### Why is the activity built when the post is written, not when it is sent?

A retry must send what the first attempt meant to send. Rebuilding at delivery
time would let an edit change the payload between attempts and make the
receiver's idempotency checks meaningless.

### How does a peer find my actor?

A peer resolves `@alice@social.example` with WebFinger:
`GET /.well-known/webfinger?resource=acct%3Aalice%40social.example`. The JRD's
`self` link points at the actor document, which carries the key id, the public
key, the inbox and the outbox. See [WebFinger](guides/webfinger.md) and
[peer discovery](guides/peer-discovery.md).

### Where does a peer get my posts?

`GET /@{handle}/outbox` lists the account's public and unlisted posts as
`Create` activities, newest first. The collection id is the same `outbox` URL
the actor document advertises. Followers-only posts are not listed, because
the route is public. See [outbox](guides/outbox.md).

### What is the difference between followers and following?

`followers` lists the actors that follow this account; `following` lists the
actors this account follows. They are separate repositories because the
directions are opposite. Following is populated by `FollowingService`; there
is no local follow HTTP route yet, and outbound `Follow` delivery is still
open. See [following collection](guides/following-collection.md).

### How is an inbound activity authenticated?

By its HTTP signature over `(request-target) host date digest`. The `keyid` is
resolved to an actor through the `ActorResolver` port, the signature is
verified against that actor's key, and the activity's `actor` must be that
same actor. A session cookie plays no part. See
[inbound federation](guides/inbound-federation.md).

### Why is a replayed activity refused?

Because a valid signature stays valid for five minutes. Without an
`ActivityLog`, anybody who captured a request could deliver it again — a
second Follow would be harmless, but the principle is not. Accepted activity
ids are remembered for a day.

### Why does a repeated Follow return 200 instead of 201?

The actor was already a follower; only the inbox may have changed. 200 says
"nothing new was created", which is exactly what happened.

### Can two accounts have the same follower?

Yes. `(local_user_id, actor_id)` is unique, not `actor_id`. The same remote
actor can follow several local accounts, each with its own inbox record.

### How do I add a feature that needs something from the host?

Add a port in `src/Platform/`, implement it in both hosts, and add a contract
test. Do not import the host.
