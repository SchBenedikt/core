# WebFinger

```http
GET /.well-known/webfinger?resource=acct:alice@social.example
Accept: application/jrd+json
```

Turns a handle into the actor document. This is the step that makes
`@alice@social.example` usable without knowing the URL convention.

## Example

```json
{
  "subject": "acct:alice@social.example",
  "links": [
    {
      "rel": "self",
      "type": "application/activity+json",
      "href": "https://social.example/@alice"
    }
  ]
}
```

`Content-Type` is `application/jrd+json; charset=utf-8`.

## Resource

`resource` must be an `acct:` URI: `acct:{handle}@{host}`. The scheme is
case-insensitive, the host is compared case-insensitively, and the handle is
matched exactly against `LocalActorDirectory`.

Accepted host forms:

- the configured host, e.g. `social.example`;
- the configured host with its port when `instance.url` carries one, e.g.
  `localhost:8080`.

A bare host is also accepted when the configured URL has a port, so a
development instance can be addressed without writing the port in the handle.

## The self link

There is exactly one link: `rel` is `self`, `type` is
`application/activity+json`, and `href` is the actor id. The href comes from
`ActivityFactory::actorId()` — the same function the actor route builds its
document id with. The test
`WebFingerTest::testAHandleResolvesToTheServedActorDocument` fetches
`/@alice` and compares the served `id` with this href, so the URL is not
written down twice.

`subject` is the canonical `acct:` URI built from the handle and the configured
authority. It identifies the same account even when the request omitted the
port.

## What a peer does

1. `GET /.well-known/webfinger?resource=acct%3Aalice%40social.example`
2. Take the `self` link whose `type` is `application/activity+json`.
3. Fetch that href. It is the [actor document](../reference/actor-document.md)
   with the key, inbox, followers and outbox.
4. Follow, deliver or read the outbox as described in
   [peer discovery](peer-discovery.md).

## Errors

| Status | Code | When |
| --- | --- | --- |
| 400 | `invalid_resource` | `resource` is missing, empty or not an `acct:` URI with a handle and a host |
| 404 | `actor_not_found` | The host is not this instance or the handle is unknown; the answer does not say which |
| 503 | `actor_not_configured` | No signing key or no local actor directory configured |
| 503 | `instance_not_configured` | `social/instance.url` is missing or invalid |

A foreign host and an unknown handle deliberately share the same 404. A prober
must not be able to enumerate local accounts by comparing the two, and neither
the handle nor the host appears in the error body.

## Not implemented

- other WebFinger link relations (profile page, activity stream aliases);
- resolving non-`acct:` resources;
- caching headers (the answer comes from configuration and one directory
  lookup, so a host may cache it).
