# Peer discovery

What a remote server does with this instance, in order.

## 1. Find the actor

A peer that knows the actor URL fetches it:

```http
GET https://social.example/@alice
Accept: application/activity+json
```

The core does not implement WebFinger yet, so a peer cannot resolve
`@alice@social.example` to the URL on its own. The URL is
`{instance}/@{urlencoded handle}`; a host that knows its accounts can publish
it, and the [roadmap](../roadmap.md) lists WebFinger as the next discovery
step.

The response is the [actor document](../reference/actor-document.md).

## 2. Read the key and the inbox

From the document a peer takes three things:

- `publicKey.id` — the key id it must put in a signature;
- `publicKey.publicKeyPem` — the key it verifies the instance's signatures with;
- `inbox` — where it delivers activities.

## 3. Deliver a Follow

```http
POST https://social.example/inbox
Signature: keyId="https://remote.example/users/bob#main-key",algorithm="rsa-sha256",
           headers="(request-target) host date digest",signature="…"

{"id":"https://remote.example/activities/follow-1",
 "type":"Follow",
 "actor":"https://remote.example/users/bob",
 "object":"https://social.example/@alice"}
```

The instance resolves the peer's key id through `HttpActorResolver` (a GET of
the actor document named by the key id), verifies the signature, checks that
the activity actor owns the key, and only then stores the edge. Details and
refusals: [inbound federation](inbound-federation.md) and
[inbox activities](../reference/inbox-activities.md).

## 4. Receive the Accept

The instance queues an `Accept` of the embedded Follow to the peer's inbox,
signed with the local actor's key. The peer matches it by the Follow id.

## 5. Read the outbox

The document's `outbox` is a real route, not a placeholder. A peer fetches it
for the account's public posts:

```http
GET https://social.example/@alice/outbox
```

Every item is a `Create` whose `object` is the Note. The activity id is the
same one delivery posts to an inbox, so a peer that saw both can deduplicate
by id instead of by content. Details: [outbox](outbox.md).

## The circle closes

The same `ActivityFactory` builds the actor id, the key id and every outbound
signature, and `HttpActorResolver` compares what it fetches with what the
signature claimed:

```
document.publicKey.id  ==  signature keyId  ==  ActorFactory::keyId(handle)
document.id            ==  activity.actor ==  ActorFactory::actorId(handle)
document.inbox         ==  the route POST /inbox
```

The test `ActorDocumentTest::testAPeerCanFetchTheDocumentAndSignAnAcceptedFollow`
walks exactly this path with the document served over a fake HTTP client: a
Follow signed with the published key id is accepted, proving the values agree.

## What a peer cannot do yet

- resolve `@handle@host` (no WebFinger);
- page through followers or the outbox (both collections are complete but not
  paged);
- fetch a single post over ActivityPub (objects are embedded in the `Create`;
  the JSON API is for local clients);
- deliver posts to this instance (`POST /inbox` applies only `Follow` and
  `Undo(Follow)`).
