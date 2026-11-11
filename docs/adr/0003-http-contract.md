# ADR 0003 — The HTTP surface is one contract

- Status: accepted
- Date: 2026-11-11

## Context

Two hosts, one API. If each host declares its own routes and controllers, the
standalone drifts from the Nextcloud app the first time somebody adds an
endpoint in a hurry — and the Vue app, which talks to both, breaks in a way
nobody notices until a user does.

Hand-written controllers per host would also mean every feature is written
twice, which defeats ADR 0001.

## Decision

**`src/Contract/Routes.php` is the only place a route exists.**

```php
new Route(
    name: 'posts.create',
    method: 'POST',
    path: '/api/v1/posts',
    handler: CreatePostHandler::class,
    requiresAuth: true,
    rateLimit: '30/900',
)
```

1. **One manifest.** `RouteManifest` refuses duplicate names and duplicate
   method+path pairs at construction. `bin/export-routes` prints it as JSON; a
   host's CI compares that document with its own registered routes.
2. **One dispatcher per host.** The standalone host and the Nextcloud host each
   wire a generic adapter: manifest route → kernel → handler. The Nextcloud
   adapter lives in the app and delegates to `Core\Http\Kernel`; it is not a
   second implementation of a handler.
3. **Handlers are core code.** A handler reads the request, calls a service and
   shapes the response. It holds no host state.
4. **Page controllers stay host-specific.** A server-rendered page needs
   Nextcloud's `TemplateResponse` or Twig, its CSRF annotations, its initial
   state. Those live in the host. This is the one accepted duplication, and it
   is bounded to presentation.
5. **One error shape.** Every error answers
   `{"error": {"code", "message", "fields?"}}` with a stable `code`. The kernel
   renders it, so a new handler cannot invent a fourth shape.

## Consequences

- Adding an endpoint is: a route in the manifest, a handler in `src/Api`, a
  platform adapter in each host. No per-host business logic.
- A host that forgets a route fails the parity check instead of shipping a gap.
- `requires_auth` and `rate_limit` are declared data, not comments. The core
  enforces authentication through middleware; each host enforces its own rate
  limiting with the declared budget.
- Versioning is explicit: the current surface is `/api/v1`, and
  `ApiVersion::CURRENT` is the single constant behind it.

## Follow-ups

- Generate an OpenAPI document from the manifest for client generation.
- Add the parity test to the Nextcloud app's CI and to the standalone's.
- Extend the manifest with response schemas once the API grows beyond posts.
