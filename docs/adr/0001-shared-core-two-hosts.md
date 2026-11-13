# ADR 0001 — One core, two hosts

- Status: accepted
- Date: 2026-11-11

## Context

Aloha Social ships as a Nextcloud app: PHP 8.3–8.5, `appinfo/info.xml`,
Nextcloud 34–36, a Vue 3 front end bundled into `js/`, and a large service
layer under `lib/Service`. Everything it needs to run — accounts, sessions,
database access, appdata storage, cron, templates, routing, CSRF — is
provided by Nextcloud.

We want a second product: the same application installed on a server **without
Nextcloud**, by a script or a container. And we want a feature to land in both
products at the same speed, without a second implementation of the federation
logic.

A fork is the obvious shortcut and the obvious failure: two codebases that
start identical and diverge on the first busy week. A rewrite in another
language is worse: HTTP signatures, the AT Protocol stack and the delivery
queue are years of accumulated behaviour.

## Decision

**The application logic lives in one core package; the products are thin
hosts around it.**

```
                    alohasocial/core   (this repository)
                    domain · services · protocols · contract
                       │                        │
        AlohaSocial/social              AlohaSocial/server
        Nextcloud host                  standalone host
        (OCP adapters, NC pages)        (kernel, auth, installer)
```

1. The core is a Composer package (`alohasocial/core`) and, once the UI moves,
   an npm package (`@alohasocial/ui`). It contains no host code. A token-based
   guard in CI fails the build if `OCP\`, `OCA\` or a `nextcloud/*` dependency
   appears in `src/`.
2. Everything a host must provide is a port in `src/Platform/`: config, clock,
   blob storage, database, outbound HTTP — and later users, sessions, jobs and
   mail. Ports are few and small on purpose; each one is a compatibility
   promise to both hosts.
3. Each host owns its bootstrap, its authentication, its templates and its
   admin pages. **HTTP page controllers stay host-specific**; the JSON API does
   not (see [ADR 0003](0003-http-contract.md)).
4. Versions: the core is semver-tagged, both hosts pin it, and a dependency bot
   opens the bump PR. A feature is therefore one code PR in the core plus two
   automated version bumps — not two implementations.
5. Three repositories, one contract each: core, `social`, `server`. They are
   not merged into a monorepo now, because the Nextcloud app's release
   machinery (App Store archive, committed `js/`, Transifex, REUSE) is delicate
   and moving it is a risk with no benefit to this decision.

## Consequences

**Good**

- Federation, validation and protocol behaviour exist once. A fix is a fix for
  both products.
- The core can be tested without a server, which is what makes the suite here
  fast: it runs in well under a second.
- The Nextcloud app keeps its native integration: it still uses Nextcloud
  routing, users and appdata, through adapters.

**Costs**

- Every host-side feature needs a thin controller and a template in each host.
  That is the accepted price; it is 20–40 lines and it is where the hosts are
  actually allowed to differ.
- `src/Platform/` is a public API. Changing an interface is a breaking change
  for both hosts and needs a major version.
- Two release pipelines still exist. The core is the thing that must be
  released first; a host pinning an old core gets old behaviour, by design.

**Rejected alternatives**

- *Fork the app* — certain divergence, double maintenance.
- *Rewrite the backend* — throws away the protocol work and delays the
  standalone by years.
- *A "fake Nextcloud" shim that implements `OCP\*`* — tempting because the
  existing code would run unchanged, but it makes Nextcloud's moving API the
  core's architecture. Only acceptable as a temporary scaffold for migrations,
  never as the design.
- *Monorepo now* — the release mechanics of the Nextcloud app are the riskiest
  part of the project; they are not touched while the core is being built.

## Follow-ups

- Migrate the existing Nextcloud data and features incrementally, preserving
  actor, key, post and follow identities. See
  [ADR 0004](0004-incremental-migration-and-ownership.md) and the
  [Nextcloud migration guide](../guides/migration-from-nextcloud.md).
- Move the Vue front end into `@alohasocial/ui` with a `HostBridge` per host;
  the contract is in [ADR 0005](0005-shared-ui.md) and the
  [shared UI guide](../guides/shared-ui.md).
- Add ports for jobs or other genuinely shared capabilities when a host needs
  them. Accounts, sessions and roles are settled as host-owned by
  [ADR 0006](0006-host-owned-accounts.md); do not add a user/session port for
  the standalone.
- Add a parity test in both hosts that compares their registered routes with
  `bin/export-routes`.

## Implementation status

As of 2026-11-13: the boundary guard, the platform ports, the durable queue and
replay log are implemented, and `@alohasocial/ui` exists under `ui/`.
[ADR 0006](0006-host-owned-accounts.md) (accepted) keeps accounts, sessions,
credentials and roles host-owned instead of adding a user/identity port; the
host parity tests are still open (M3). This note records progress; it does not
change the decision.
