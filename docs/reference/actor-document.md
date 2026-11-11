# Actor document

```http
GET /@{handle}
Accept: application/activity+json
```

Serves the ActivityStreams document of a local account. It is public: a peer
fetches it before it can follow, address or deliver to the account. The
handler is described in [peer discovery](../guides/peer-discovery.md).

## Example

```json
{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://w3id.org/security/v1"
  ],
  "id": "https://social.example/@alice",
  "type": "Person",
  "preferredUsername": "alice",
  "name": "alice",
  "inbox": "https://social.example/inbox",
  "outbox": "https://social.example/@alice/outbox",
  "followers": "https://social.example/@alice/followers",
  "publicKey": {
    "id": "https://social.example/@alice#main-key",
    "owner": "https://social.example/@alice",
    "publicKeyPem": "-----BEGIN PUBLIC KEY-----\n…\n-----END PUBLIC KEY-----\n"
  }
}
```

`Content-Type` is `application/activity+json; charset=utf-8`.

## Fields

| Field | Value | Why |
| --- | --- | --- |
| `@context` | ActivityStreams + security vocabulary | `publicKey` is defined by the security context, not by ActivityStreams |
| `id` | `{instance}/@{urlencoded handle}` | Canonical actor URL; the same value every other URL is derived from |
| `type` | `Person` | The core has one kind of local actor |
| `preferredUsername` | the handle, without `@` | What a peer shows and matches |
| `name` | the handle | Display names are a host concern; the core has no profile store |
| `inbox` | `{instance}/inbox` | The endpoint that actually accepts signed activities |
| `outbox` | `{actor}/outbox` | The public outbox collection; the same id the route serves |
| `followers` | `{actor}/followers` | The collection route |
| `publicKey.id` | `{actor}#main-key` | **Exactly** the key id the inbox resolves on a signed request |
| `publicKey.owner` | `{actor}` | The actor that owns the key |
| `publicKey.pem` | the instance public key | What a peer verifies the instance's signatures with |

## Invariants

- `id` is built by `ActivityFactory::actorId()`, the same function that signs
  outbound activities and that `HttpActorResolver` compares against.
- `publicKey.id` is built by `ActivityFactory::keyId()`, the same function that
  produces the `keyid` of outbound signatures and that inbound verification
  resolves.
- `inbox` is the path of the `inbox` route in the manifest, not a second
  endpoint that only looks similar.
- `followers` is the path of the `actors.followers` route.
- `outbox` is built by `ActivityFactory::outboxId()`, the same function the
  [outbox collection](../guides/outbox.md) uses for its `id`.

A test asserts all three, so the document cannot drift away from the routes.

## Errors

| Status | Code | When |
| --- | --- | --- |
| 404 | `actor_not_found` | The handle is not an account on this instance. The message never echoes the handle. |
| 503 | `actor_not_configured` | No signing key or no local actor directory configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing or not http(s) |
