# ADR 0005 — One Vue UI, with a host bridge

- Status: accepted
- Date: 2026-11-12

## Context

Both Aloha Social products need the same posts, timelines, follow controls,
actor pages, outbox and inbox feedback. The current Vue front end is embedded
in the Nextcloud app and reaches Nextcloud globals directly. The standalone
server will need the same screens but has a different login flow, URL prefix,
CSRF mechanism and navigation.

Copying the components into both hosts would recreate the backend problem on
the front end: two button implementations, two API clients and two places to
fix an accessibility bug.

[ADR 0001](0001-shared-core-two-hosts.md) already names the intended package,
`@alohasocial/ui`. This ADR makes its boundary concrete.

## Decision

### 1. The UI is a separate package

`@alohasocial/ui` owns the shared Vue 3 components, composables and the
client-side API transport. It is published as an npm package and versioned
independently with semver. The package has a peer dependency on Vue 3; it does
not bundle a second application runtime.

The package source lives in this repository under `ui/` when extraction starts,
alongside the Composer package. It is a separate artifact, not a second PHP
application. The host repositories consume it as a normal dependency and do not
copy its source.

### 2. The host bridge is the only host boundary

The UI may not read `OC`, `window` globals, cookies, a server session or a
host-specific config object. It receives one typed object:

```ts
export interface HostBridge {
  /** Prefix under which the core's instance.url is served, no trailing slash. */
  readonly apiBase: string;

  /** Optional; omit for the original contract 1. */
  readonly contractVersion?: number;

  currentUser(): Promise<UiUser | null>;

  /**
   * Resolves a core route path. The bridge applies apiBase, adds the current
   * auth/CSRF mechanism and returns the raw response.
   */
  request(request: UiRequest): Promise<UiResponse>;

  loginUrl(returnTo: string): string;
  logout(): Promise<void>;

  /** Navigate inside the host without a full page reload. */
  navigate(path: string): void;

  capabilities(): UiCapabilities;
}
```

The supporting types stay small:

```ts
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
  /** Add a capability only when a host genuinely varies. */
}
```

`path` is the core route path (`/api/v1/posts`, `/@alice/outbox`), not a host
controller path. The bridge is the only code that knows about a Nextcloud
`requesttoken`, a bearer header, a cookie or a path prefix.

The bridge must not expose tokens to components. `request()` is the security
boundary: it attaches credentials, enforces the host origin and returns a
structured failure for a non-2xx response. A 401 is passed to the host's login
flow; the UI does not redirect by reading a host global.

### 3. Components use the core HTTP contract

The UI talks to `Routes::manifest()` paths, never to a host database or a
private Nextcloud endpoint. The route export is the machine-readable contract
for both hosts. A component that needs a new endpoint cannot add a local URL;
the endpoint is added to the core manifest first.

The bridge can be implemented over `fetch`, Nextcloud's HTTP client or a
server-generated test double. The UI package ships a `FakeHostBridge` for
component tests; it must make no network requests.

### 4. Host responsibilities

Each host provides only:

- a `HostBridge` implementation;
- a mount page (Nextcloud template or standalone controller) that creates the
  Vue app with that bridge;
- host navigation, login/logout and theming around the shared app.

The host may keep a page that is genuinely host-specific (admin settings,
installer), but it must not fork a shared component to change a label. Shared
copy and behaviour belong in the package.

The Nextcloud bridge reads the current user and adds the Nextcloud request
token. The standalone bridge reads its session and adds its own CSRF/header
policy. Neither implementation belongs in `@alohasocial/ui`.

### 5. Extraction and rollout

The UI is extracted only after the corresponding API slice has passed its
migration gates. The order is:

1. define the `HostBridge` contract and a fake implementation;
2. move one read-only screen (public timeline or post detail) with its tests;
3. add the bridge to the Nextcloud host behind a flag and verify login,
   request, 401 and logout;
4. add the standalone bridge when its auth flow exists;
5. move one write screen (post editor) only after both hosts can submit it;
6. remove the old component only when the shared page covers all of its
   behaviour.

A shared page is not a reason to migrate its API. The backend slice must be
stable first.

### 6. Package rules

- Vue 3 components are presentational; data loading lives in composables.
- No `OCP`, Nextcloud controller, database or server filesystem import.
- No hard-coded host URL, login URL or CSRF header.
- CSS is scoped or uses documented custom properties; no global reset that
  changes the host page.
- Accessible names, keyboard operation and focus behaviour are tested in the
  package, not left to each host.
- A breaking change to `HostBridge` or a component prop is a major package
  version. The bridge also carries an optional `contractVersion`; omitting it
  means contract `1`, and a breaking bridge change increments the constant.
  `@alohasocial/ui` rejects a declared newer contract at `createAlohaUi()`
  instead of calling an unknown surface.

## Consequences

**Good**

- One implementation of every shared screen and one place for accessibility
  and interaction fixes.
- Hosts keep their own authentication and deployment models behind a small,
  testable seam.
- The UI follows the same HTTP contract as the backend, so route drift is
  visible in one parity test.

**Costs**

- The host must implement and test a bridge before it gets any shared screen.
- The package needs its own build, release and browser test pipeline.
- A feature that needs a host-only capability must be expressed as a
  capability or kept outside the shared UI; not every page belongs here.

**Rejected alternatives**

- **Copy the Vue components into each host.** It creates two component
  libraries and guarantees drift.
- **Let components read Nextcloud globals.** It makes the standalone
  impossible without a fake Nextcloud, which ADR 0001 rejects.
- **Put the bridge in the PHP core.** Authentication and CSRF are host
  concerns; the PHP core must not learn either host's session model.
- **Wait for a perfect design system.** A small, tested bridge and one screen
  are enough to validate the boundary; the rest follows the same pattern.

## Follow-ups

- Create the `ui/` package with the TypeScript contract, a fake bridge and one
  read-only component test.
- Add a Nextcloud bridge test that proves the request token is attached and
  never exposed to component code.
- Add a standalone bridge only when `AlohaSocial/server` has an auth flow.

## Implementation status

As of 2026-11-13: the package, `HostBridge`, `FakeHostBridge`, `CoreApi`,
`PostCard`, `PublicTimeline`, `PostDetail`, `ActorHeader`, `PostComposer` and
`NotificationList` are implemented under `ui/`. The neutral profile/follow and
notification routes they consume are part of the core contract.
`ui/src/version.ts` exports the UI version, core API version and HostBridge
contract; `ui/tests/compatibility.test.ts` checks them against the package and
lockfile and rejects an incompatible bridge. Host bridges and mount pages are
open; M5 in [the roadmap](../roadmap.md) tracks them. This note records
progress; it does not change the decision.
