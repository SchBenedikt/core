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
| `authentication_required` | 401 | The route needs a user and the request has none; carries `WWW-Authenticate: Bearer` |
| `forbidden` | 403 | The user is known but may not do this (deleting somebody else's post) |
| `not_found` | 404 | Missing — or private and therefore indistinguishable from missing |
| `method_not_allowed` | 405 | The path exists for another method; carries `Allow` |
| `validation_failed` | 422 | Input violates the contract; `fields` names each problem |
| `internal_error` | 500 | Unexpected; the message is hidden unless debug mode is on |
| `instance_not_configured` | 503 | A post cannot be federated because `social/instance.url` is missing or invalid |

## Rules

- Never add a code without adding it to this table.
- Never reuse a code for a different meaning; clients switch on it.
- A 404 for a private resource is deliberate. Do not "improve" it to 403.
- `fields` keys are the JSON field names (`content`, `content_warning`,
  `visibility`, `actor`, `inbox`), never internal column names.
- The kernel owns the shape; a handler that builds an error object by hand is a
  bug waiting to happen.
