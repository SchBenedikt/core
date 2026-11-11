# HTTP and routes

The core serves one JSON API. Both hosts mount it; neither writes a handler.

## Lifecycle

```
Request ──► Router::match ──► Kernel::bind ──► middleware ──► Handler ──► Response
              │ 404/405                          │ 401
              └──────────────────────────────────┴──► HttpException::toResponse
```

1. **Route.** `Router::match()` tries static paths before parameterised ones,
   answers 405 with an `Allow` header when the path exists for another method,
   and decodes path parameters **after** matching. A parameter therefore cannot
   swallow a slash, but an encoded `%2F` inside one segment survives.
2. **Bind.** The route and its parameters become request attributes, so a
   handler reads `$request->attribute('id')` and never parses a URL.
3. **Middleware.** `RequireAuthentication` refuses a route declared with
   `requiresAuth: true` when `userId` is null. Middleware is outermost first.
4. **Handler.** Built lazily from `HandlerMap`; a handler only sees the service
   it needs.
5. **Errors.** Every `HttpException` becomes the documented shape; anything
   else is logged and answered with a 500 that hides the message unless the
   kernel runs in debug mode.

## Adding an endpoint

1. Add a `Route` to `src/Contract/Routes.php` — this is the only place a route
   exists. Pick a name (`posts.create`), a method, a path under `/api/v1`, the
   handler class and the auth requirement.
2. Write the handler in `src/Api/`. It maps input to a service call and the
   result to a response; it holds no rules.
3. Register the handler in `Application::inMemory()` (the reference assembly)
   so the boot check passes.
4. Add the host adapters. The standalone host registers the route in its
   router; the Nextcloud host adds it to its route map. Both compare their
   routes against `bin/export-routes`.
5. Document it in [the route reference](../reference/routes.md).

## Conventions

- **Paths** start with `/api/v1`. `ApiVersion::BASE_PATH` is the single
  constant; never write the string twice.
- **Names** are `resource.action`, lower case: `followers.unfollow`.
- **Auth** is declared, not implied. A route that reads private data sets
  `requiresAuth: true`; a public route sets it false explicitly.
- **Rate limits** are declared as `<requests>/<seconds>` and enforced by the
  host. The core documents the budget; it does not count requests.
- **Errors** always use `{"error": {"code", "message", "fields"?}}`. Codes are
  stable; see [error codes](../reference/error-codes.md).
- **Lists** return a named array (`posts`, `followers`), never a bare array, so
  a field can be added later without breaking clients.

## Status codes used

| Code | When |
| --- | --- |
| 200 | Read or idempotent write |
| 201 | Created; carries `Location` |
| 204 | Deleted; empty body |
| 400 | Malformed JSON or an invalid cursor |
| 401 | The route needs a user |
| 403 | The user exists but may not do this |
| 404 | Missing — or private and therefore indistinguishable from missing |
| 405 | Wrong method for an existing path, with `Allow` |
| 422 | Validation failed, with `fields` |
| 500 | Unexpected; no internals leaked |
| 503 | A dependency the core needs is not configured |

## Why 404 instead of 403 for private posts

A 403 confirms that a post with this id exists. For a followers-only or direct
post that is already a leak. Reads of non-public posts answer 404, exactly as
if the id were unknown; the author still sees the post because the check
includes the viewer.
