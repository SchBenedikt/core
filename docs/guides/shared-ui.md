# Shared UI and HostBridge

The shared front end is planned as `@alohasocial/ui`, a Vue 3 package used by
the Nextcloud app and the standalone server. This guide is the practical
contract for that package; [ADR 0005](../adr/0005-shared-ui.md) explains why
the boundary is drawn this way.

The package does not exist in this repository yet. This guide defines what it
must expose before the first component is moved out of `social`; it is not a
claim that extraction has happened.

## The boundary

```
┌───────────────────────────────┐
│ @alohasocial/ui               │
│ Vue components + composables  │
│ no OCP, no server runtime     │
└──────────────┬────────────────┘
               │ HostBridge
      ┌────────┴─────────┐
      ▼                  ▼
Nextcloud bridge     Standalone bridge
(OCP page)           (server page)
```

The UI may know the core HTTP contract and its own state. It must not know
which host is running it. The bridge is the only object that knows about a
session, a CSRF token, a URL prefix or a login redirect.

## HostBridge

The package exports the contract; each host implements it. Keep the surface
small enough that a host can implement and test it without exposing its
internals.

```ts
export interface HostBridge {
  readonly apiBase: string;

  currentUser(): Promise<UiUser | null>;

  request(request: UiRequest): Promise<UiResponse>;

  loginUrl(returnTo: string): string;
  logout(): Promise<void>;

  navigate(path: string): void;

  capabilities(): UiCapabilities;
}

export interface UiUser {
  id: string;
  handle: string;
  displayName?: string;
}

export interface UiRequest {
  method: 'GET' | 'POST' | 'DELETE';
  path: string;
  query?: Record<string, string>;
  body?: unknown;
}

export interface UiResponse {
  status: number;
  headers: Record<string, string>;
  body: string;
}

export interface UiCapabilities {
  media: boolean;
  atproto: boolean;
}
```

### Rules for the bridge

- `apiBase` is the public prefix where the core `instance.url` is served. It
  has no trailing slash.
- `request()` is the only transport. It applies `apiBase`, attaches the host's
  auth/CSRF mechanism, uses the host's origin policy and returns the raw
  response. Components never call `fetch()` directly.
- A 401 is returned as a response or handled by the host's login flow. Do not
  put a session token in `localStorage` from a component.
- `loginUrl()` returns a full URL the host controls; `logout()` performs the
  host's logout, including its CSRF requirements.
- `navigate()` is for in-host navigation without a full reload. A host that
  cannot do that may perform a normal navigation; the UI must not depend on a
  router implementation.
- `capabilities()` is for features that genuinely differ, not for hiding a
  broken route. A route in the core manifest is expected to exist in both
  hosts.

The host bridge is not a place for business logic. It authenticates and
transports; the core decides visibility, validation and federation.

## Package layout

When extraction starts, keep the package isolated from the PHP tree:

```
ui/
  package.json          @alohasocial/ui, peerDependency vue
  tsconfig.json
  src/
    host-bridge.ts      the exported contract and types
    components/         shared Vue components
    composables/        data loading and API calls
    testing/            FakeHostBridge and fixtures
    index.ts            public exports
  README.md
```

The PHP autoloader and the npm package must not import each other's internals.
A release publishes the npm artifact and the Composer package separately; a
host pins both versions.

## What belongs in the package

- post cards, timelines, follow buttons and collection lists;
- actor header and profile-level presentation that both products share;
- error, empty and loading states for core routes;
- composables that call `HostBridge.request()` and parse the core's JSON error
  shape;
- accessible focus, keyboard and label behaviour;
- a fake bridge for component tests.

## What stays in the host

- login, logout, account creation, password reset and session UI;
- admin settings and installer pages;
- Nextcloud navigation, app menu, templates and theming shell;
- server-specific deployment or health pages;
- a page whose data does not come from the core.

If both hosts need a new screen, move it into the package. If only one host
needs it, keep it there. Do not fork a shared component to change one label;
make the label a prop or a translated slot in the package.

## Nextcloud bridge sketch

The Nextcloud page owns the bridge implementation:

```ts
const bridge: HostBridge = {
  apiBase: '/apps/social/federation',

  async currentUser() {
    const user = OC.currentUser;
    return user ? { id: user, handle: user } : null;
  },

  async request({ method, path, query, body }) {
    const response = await fetch(withQuery(bridge.apiBase + path, query), {
      method,
      headers: {
        'Content-Type': 'application/json',
        requesttoken: OC.requestToken,
      },
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    return {
      status: response.status,
      headers: Object.fromEntries(response.headers.entries()),
      body: await response.text(),
    };
  },

  loginUrl(returnTo) {
    return `/login?redirect_url=${encodeURIComponent(returnTo)}`;
  },

  async logout() {
    window.location.assign('/logout');
  },

  navigate(path) {
    window.location.assign(path);
  },

  capabilities() {
    return { media: false, atproto: false };
  },
};
```

This is a sketch, not code to paste into the core. The actual adapter belongs
in `AlohaSocial/social`, must use the app's established Nextcloud request
helpers where available, and must not leak `OC.requestToken` to a component.

The standalone bridge has the same shape but reads its own session and uses its
own CSRF/header policy. It does not get a Nextcloud compatibility shim.

## Mounting

The host creates the app once at its page boundary:

```ts
import { createApp } from 'vue';
import { createAlohaUi } from '@alohasocial/ui';
import App from './App.vue';
import { bridge } from './bridge';

createApp(App).use(createAlohaUi({ bridge })).mount('#aloha-social');
```

`createAlohaUi()` provides the bridge through Vue's dependency injection. A
component uses a composable such as `useHostBridge()`; it does not import a
singleton or read a global.

## Testing

The package owns a fake implementation:

```ts
const bridge = new FakeHostBridge({
  user: { id: 'alice', handle: 'alice' },
  responses: {
    'GET /api/v1/timelines/public': {
      status: 200,
      body: JSON.stringify({ posts: [] }),
    },
  },
});
```

Tests in the package cover:

- a component's loading, empty, error and success states;
- request paths against `Routes::manifest()` paths;
- a 401 path without a token leak;
- keyboard navigation and accessible labels.

Each host adds one integration test:

1. mount the shared app with its real bridge;
2. assert `currentUser()` maps the host session;
3. perform one read and one write;
4. assert the host's auth/CSRF header was attached;
5. assert logout returns to the host login.

A host smoke test is not a replacement for the package tests.

## Rollout

1. Extract one read-only screen and its component tests.
2. Add the Nextcloud bridge behind a feature flag.
3. Verify login, request, 401 and logout in the real page.
4. Add the standalone bridge when its auth flow exists.
5. Move a write screen only after both hosts can complete it.
6. Remove the old screen when parity is confirmed.

The backend slice must be migrated first. A shared component is not a reason to
move an API that still has identity or data-parity problems.
