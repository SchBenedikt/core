# Error codes

Every failure the core answers has this shape:

```json
{"error": {"code": "validation_failed", "message": "…", "fields": {"content": "…"}}}
```

`code` is stable and meant for clients. `message` is human-readable and may
change. `fields` appears only when an error belongs to specific input.

| Code | Status | When |
| --- | --- | --- |
| `invalid_json` | 400 | The body is not valid JSON or not an object |
| `invalid_cursor` | 400 | `before` is not a canonical timestamp |
| `invalid_resource` | 400 | WebFinger `resource` is missing or not a usable `acct:` URI |
| `authentication_required` | 401 | The route needs a user and the request has none; carries `WWW-Authenticate: Bearer` |
| `forbidden` | 403 | The user is known but may not do this (deleting somebody else's post) |
| `not_found` | 404 | Missing — or private and therefore indistinguishable from missing |
| `unknown_actor` | 404 | An inbox request signed with a key id that does not resolve to an actor |
| `unknown_object` | 404 | An inbox activity does not address an actor on this instance |
| `actor_mismatch` | 403 | A valid inbox signature whose activity claims another actor, a created object belongs to another actor, or a Delete targets another actor's row |
| `blocked_actor` | 403 | The host's blocked-actor policy refuses the signing actor |
| `not_followed` | 403 | Inbound `Create`/`Delete`/`Announce` from an actor no local account follows |
| `signature_required` | 401 | An inbox request without a `Signature` header |
| `invalid_signature` | 401 | Malformed header, missing signed header, digest mismatch, wrong key or unsupported algorithm |
| `signature_expired` | 401 | The signed `Date` is outside the 300-second window |
| `invalid_activity` | 400 | An inbox activity without `id`, `type` or `actor`, or with a malformed object |
| `unsupported_activity` | 400 | An inbox activity type the core does not apply |
| `replayed_activity` | 400 | An activity id that was already accepted |
| `inbox_not_configured` | 503 | No actor resolver, HTTP client or signing key configured |
| `delivery_failed` | 502 | A peer inbox answered non-2xx or could not be reached while delivering a local Follow or Undo. A Follow is rolled back; an Undo keeps the edge so the request can be retried |
| `federation_not_configured` | 503 | A local follow write has no HTTP client or signing key to deliver the Follow/Undo |
| `actor_not_found` | 404 | A handle that is not an account on this instance, or a WebFinger host that is not this instance (actor document, followers/following collections, outbox, WebFinger) |
| `actor_not_configured` | 503 | No signing key or no local actor directory configured (actor document, followers/following collections, outbox, WebFinger) |
| `method_not_allowed` | 405 | The path exists for another method; carries `Allow` |
| `validation_failed` | 422 | Input violates the contract; `fields` names each problem |
| `internal_error` | 500 | Unexpected; the message is hidden unless debug mode is on |
| `instance_not_configured` | 503 | `social/instance.url` is missing or invalid, so no actor or federation URL can be built |

## Rules

- Never add a code without adding it to this table.
- Never reuse a code for a different meaning; clients switch on it.
- A 404 for a private resource is deliberate. Do not "improve" it to 403.
- `fields` keys are the JSON field names (`content`, `content_warning`,
  `visibility`, `actor`, `inbox`), never internal column names.
- The kernel owns the shape; a handler that builds an error object by hand is a
  bug waiting to happen.
