# @alohasocial/ui

Shared Vue 3 components, composables and the `HostBridge` contract for the
Nextcloud and standalone Aloha Social hosts. The package is a separate npm
artifact under `ui/`; it never imports a host, OCP, a server runtime or a
database.

See [ADR 0005](../docs/adr/0005-shared-ui.md) and the
[shared UI guide](../docs/guides/shared-ui.md).

## What is here

- `HostBridge`: the only host boundary. It owns `apiBase`, authentication,
  CSRF, origin policy and navigation; the package owns presentational
  components and typed core API reads.
- `createAlohaUi({ bridge })` and `useHostBridge()`: one bridge provided at the
  host page boundary through Vue dependency injection.
- `CoreApi`: typed reads over the core HTTP contract, including the documented
  JSON error shape and the `before`/`limit` public-timeline cursor.
- `PublicTimeline`, `PostDetail` and `PostCard`: the read-only shared screens.
  The timeline covers loading, empty, error, retry and load-more states; the
  detail screen reads one existing post by id and covers loading, not-found,
  error and retry states.
- `FakeHostBridge`: a deterministic, network-free bridge for component tests.

This extraction intentionally does not add a host page, a Nextcloud bridge, a
standalone bridge or a new core route. Those stay in the host repositories;
the shared package only consumes existing manifest paths. It also has no
persistence layer, schema or migration: `PublicTimeline` keeps its page state
in the component, and `PostDetail` keeps its single-post request state in its
composable, both through existing core routes.

## Development

```bash
npm ci
npm run typecheck
npm test
npm run build
```

`npm test` includes a route-parity test that invokes
`php ../bin/export-routes`, so the repository's PHP dependencies must be
installed (`composer install` at the repository root) when running that test.

## Host wiring

The host provides one bridge and mounts the shared app:

```ts
import { createApp } from 'vue';
import { createAlohaUi } from '@alohasocial/ui';
import App from './App.vue';
import { bridge } from './bridge';

import '@alohasocial/ui/style.css';

createApp(App).use(createAlohaUi({ bridge })).mount('#aloha-social');
```

A component uses `useHostBridge()` or `useCoreApi()`; it never calls `fetch()`,
reads `OC`, reads a session global or learns the host's URL prefix. The bridge
must not expose a token to component code.
