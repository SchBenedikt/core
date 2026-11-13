# ADR 0006 — Accounts, sessions and roles stay host-owned

- Status: accepted
- Date: 2026-11-13
- Related: [ADR 0001](0001-shared-core-two-hosts.md),
  [ADR 0004](0004-incremental-migration-and-ownership.md),
  [ADR 0005](0005-shared-ui.md)

## Context

`alohasocial/core` is a library with two hosts: the Nextcloud app and the
future standalone server. Both hosts present the same social product, but
their account systems are not interchangeable. Nextcloud already supplies
users, external user backends, sessions, password reset, CSRF, groups and
administrator rights. A standalone server has to create those concepts in its
own database and installer.

The identity question is therefore narrower than "where does all user data
live?" It is: **which side owns the account record, the login/session
lifecycle and the right to administer the instance?** A second question
follows from it: what may the shared Vue UI know about the current user?

The code already answers most of this:

- `Http\Request` has an optional `$userId` and a list of `$scopes`; it has no
  password, session, role or account object.
- `RequireAuthentication` only checks whether a route marked `requiresAuth`
  received a `$userId`.
- `RequireScopes` checks the token scopes the host supplied; the core does not
  issue tokens.
- `Domain\Federation\LocalActorDirectory` only answers `has(string $userId)`.
  `LocalActorSearch` and `LocalActorCount` are optional read-side capabilities.
- `HostBridge::currentUser()` returns a `UiUser` with `id`, `handle` and an
  optional `displayName`; the bridge has no role or admin field.
- `Platform\Config\Config` is app-scoped. Its own documentation says
  user-scoped settings are deliberately not part of the port yet.
- `Domain\Atproto\AtprotoSessionStore` is host-owned, but it stores an AT
  Protocol OAuth session for a linked PDS account, not an application login.
- `Domain\Federation\BlockedActorPolicy` and `FollowPolicy` let a host decide
  instance-wide policy. They are policy seams, not role checks.
- No admin route is declared in `Contract\Routes`; admin pages and role
  enforcement are not part of the core HTTP contract.

ADR 0001 originally left "users, sessions" as possible future ports. ADR 0004
already makes account import host-owned. This ADR closes the remaining
identity decision for both hosts.

The legal question of who is the data controller for a particular deployment
is not an architecture decision. It is a deployment, privacy and contract
question. This ADR decides the software boundary; it does not answer that
legal question.

## Decision

**The host is the identity authority. Accounts, credentials, sessions,
password reset, token issuance, roles and instance-administrator rights stay
host-owned. The core keeps only the authenticated local id and the
host-resolved token scopes it already receives.**

The boundary has four parts.

### 1. The core receives a trusted local user id

The host authenticates the caller and passes the resulting local account id as
`Request::$userId`. The string is opaque to the core: the core uses it as the
key for posts, follows, notifications and the other social rows, and derives
the public actor URL from configuration plus that id. It does not look the id
up in an account table, parse it as an email address or replace it with a
core-generated user id.

A null `$userId` means anonymous. The core's authentication middleware only
checks whether a route requires a user. Resource authorization in services is
about the resource, not about a global role: an actor may delete their own
post, read a post addressed to them, or follow an actor the host already
authenticated them as.

`Request::$scopes` is a separate, narrow seam for the optional
Mastodon-compatible prefix. The host authenticates the bearer token or session
and puts the granted scopes on the request. The core checks the scopes a route
declares; it does not create, refresh, revoke or persist an application token.
Scopes are not roles, and a scope never grants instance-administrator rights.

### 2. There is no core `User` domain and no `Identity` port

The core does not add:

- a user, account-credential, session, password-reset or role table;
- a `Platform\Identity`, `UserProvider` or session interface;
- a login, logout, registration, password-reset, token or admin route;
- a role or permission check that reads host data.

`LocalActorDirectory` remains the only required account-facing read port. It
exists so an actor document can answer whether a handle belongs to this
instance; it is not an authenticator. A directory may optionally implement
`LocalActorSearch` for local search and `LocalActorCount` for instance
statistics. Those capabilities are read-only and do not imply authentication.

`Config` remains instance/app configuration. User preferences, profile source
fields, role assignments and session state do not enter this port. If a host
needs per-user settings, it stores them behind its own identity boundary and
exposes only the fields a shared screen needs.

AT Protocol is not an exception. `AtprotoSessionStore` keeps the OAuth tokens
for a host-linked PDS account in the host's secret store. It is a domain port
for a remote protocol account, not a second application-session mechanism.
The core's only implementation is an in-memory test adapter.

### 3. Roles and instance administration are host concerns

The core has no role model, no group model and no administrator flag. In
particular:

- the core does not decide who may change instance settings, moderate another
  account, invite users or install the application;
- admin pages and their navigation are host pages, not routes in
  `Contract\Routes`;
- an instance-wide policy may be supplied through `BlockedActorPolicy` or
  `FollowPolicy`, but the host decides who is allowed to change that policy;
- a host must not turn a client-supplied role or user id into authority. It
  authenticates the caller, performs the role check in host code, and only then
  passes the resulting local id and scopes to the core.

The Nextcloud host keeps its existing groups and administrator checks. The
standalone host must implement its own roles and protect its own admin pages;
passing a user id to the core is not an authorization check for those pages.
This keeps Nextcloud's permission model out of the core and avoids inventing a
second, weaker role system for the standalone.

### 4. `HostBridge` is a UI transport boundary, not an identity provider

`@alohasocial/ui` receives the host bridge through Vue injection. The accepted
contract is the one in `ui/src/host-bridge.ts`:

- `apiBase` is the public prefix of the core routes;
- `currentUser()` returns `UiUser | null`, currently `{ id, handle,
  displayName? }`;
- `request()` resolves a core path through the host's authentication, CSRF and
  origin policy and returns the raw response;
- `loginUrl()`, `logout()` and `navigate()` belong to the host's navigation and
  session flow;
- `capabilities()` reports feature availability (`media`, `atproto`), not
  permissions.

`request()` is the only transport. Components never read a cookie, a session
object, `OC`, a CSRF token or an admin flag. The bridge may attach a host
credential, but it must not expose that credential to component code. A 401 is
handled by the host's login flow, not by a core redirect.

`UiUser` deliberately has no role or administrator field. A shared component
that needs a role-gated action is not a reason to add one to the core identity
model: the action belongs in a host admin page, or it needs a separate,
explicitly designed bridge capability and an update to this ADR. Presentation
of the signed-in user is not the same thing as authorization.

## Options considered

### A. Host-owned identity (chosen)

The host stores accounts, credentials, sessions, reset tokens, roles and admin
flags; the core receives an authenticated local id and token scopes.

**Advantages:** native Nextcloud integration stays intact; the standalone can
choose its own schema, password hashing and session policy; the core remains a
library; each host can evolve authentication without a core release; the
existing `Request::$userId` seam and the current tests already implement it.

**Costs:** each host implements and tests its own account and session layer;
there is no shared login implementation; account deletion and profile changes
remain host workflows.

### B. A core `User` table plus an `Identity` port

The core would own account records and expose login/session operations to both
hosts.

**Advantages:** one account schema and one place to share account behaviour.

**Costs:** the core becomes an application, needs a migration and a password
policy, and must reconcile Nextcloud's existing users and sessions with a
second store. The two hosts have genuinely different session, CSRF, reset and
role mechanisms. A lowest-common-denominator port would not remove the host
work, and it would contradict ADR 0001's library boundary. Rejected.

### C. A shared external identity or SSO provider

Both hosts would delegate login to a common identity service.

**Advantages:** central account lifecycle and potentially single sign-on.

**Costs:** it adds a deployment dependency neither product currently has,
still needs a local account mapping for social rows, and does not solve
instance-admin rights inside each host. It is a possible future host
integration, not a reason to add a core user table. Not selected.

### D. A Nextcloud compatibility shim as the long-term identity layer

The standalone server would emulate Nextcloud's session and user APIs.

**Advantages:** the existing Nextcloud integration code could be reused
unchanged.

**Costs:** it makes Nextcloud's API the core's architecture, which ADR 0001
explicitly rejects as a permanent design. It also cannot represent a
standalone installer or a standalone admin model cleanly. Rejected.

## Consequences

### Core

- No user, session, credential or role table and no `Identity` port.
- No login, logout, registration, password-reset, token-issuance or admin
  route. The manifest stays focused on social behaviour.
- `Request::$userId` is trusted input. The core assumes the host authenticated
  it; a host bug that passes an attacker-controlled id is a host security bug,
  not a core authorization feature.
- Resource ownership checks stay in services. Instance-wide policy stays
  behind `BlockedActorPolicy`/`FollowPolicy`.
- Account deletion is not a core workflow. A host that deletes an account must
  also decide what happens to its social rows; the core currently has no
  account-deletion route or cascade.

### Nextcloud

- The app keeps Nextcloud users, external user backends, sessions, password
  reset, CSRF, groups and administrator rights.
- The host maps the authenticated Nextcloud user id to `Request::$userId` and
  passes the user's token scopes where the Mastodon-compatible prefix is
  enabled.
- It implements `LocalActorDirectory` (and optional search/count capabilities)
  over its user directory; it keeps admin settings and moderation in its own
  pages.
- The Nextcloud bridge, when implemented, must read the Nextcloud session and
  attach the Nextcloud CSRF request token. It must not expose either to a
  component.

### Standalone

- The future `AlohaSocial/server` owns its own `users`, `sessions`,
  `password_resets`, API-token and role/admin storage, password hashing, rate
  limiting and CSRF policy.
- Its installer creates the first administrator account; its auth middleware
  produces `Request::$userId` and scopes exactly as the Nextcloud adapter does.
- It implements the same `LocalActorDirectory` and `HostBridge` contracts. The
  bridge reads its session; the core sees only the resulting local id.
- It must preserve the same local user id when importing legacy data, because
  the id is part of the public actor URL derivation in
  `ActivityFactory::actorId()`.

### Shared UI

- `UiUser` remains `{ id, handle, displayName? }`; no role or permission field
  is added by this decision.
- Components check whether a user is signed in through `currentUser()`, but
  never decide whether that user is an administrator.
- Admin settings, moderation and installer screens stay host pages. A future
  shared admin screen would need an explicit capability design, not a generic
  role string passed through the bridge.

### Migration and rollout

1. **No schema migration.** The core's schemas already use opaque local user
   ids. No user, session or role table is added.
2. **Nextcloud cutover.** Keep the existing Nextcloud UID, session, groups and
   admin checks. Mount the core behind the existing authenticated controller;
   preserve actor URLs and key ids under ADR 0004. The existing Nextcloud
   admin pages remain authoritative.
3. **Standalone bring-up.** Create the server's account/session/role schema and
   installer first. The installer creates the first admin; the auth middleware
   authenticates requests before the kernel. Only then mount the shared UI
   through the standalone bridge.
4. **Shared UI.** Implement `currentUser()` and `request()` in each host. Test
   one read, one write, a 401 and logout. Do not pass roles through the bridge
   until a concrete shared screen needs them.
5. **Revisit only with a new requirement.** A read-only directory interface for
   a genuinely shared account list is allowed later; an authentication port,
   user table or role model is not created by this ADR.

## Evidence in the current code

| Claim | Evidence |
| --- | --- |
| The core receives an optional local id and scopes, not an account object | `src/Http/Request.php`: `$userId`, `$scopes`; `withUserId()`, `withScopes()` |
| Authentication is only a presence check | `src/Http/Middleware/RequireAuthentication.php` checks `$route->requiresAuth` and `$request->userId === null` |
| Scopes are host-supplied and core-enforced | `src/Http/Middleware/RequireScopes.php`; `src/Http/Request.php` |
| The required account-facing read port is only `has()` | `src/Domain/Federation/LocalActorDirectory.php` |
| Search and count are optional read capabilities | `src/Domain/Federation/LocalActorSearch.php`, `src/Domain/Federation/LocalActorCount.php` |
| `Config` is app-scoped, not a user store | `src/Platform/Config/Config.php` |
| The UI user has no role field | `ui/src/types.ts` (`UiUser`); `ui/src/host-bridge.ts` (`currentUser()`) |
| The bridge, not a component, owns transport credentials | `ui/src/host-bridge.ts` (`request()`, `loginUrl()`, `logout()`) |
| AT Protocol OAuth storage is not application login | `src/Domain/Atproto/AtprotoSessionStore.php` |
| Instance policy is a host decision, not a role check | `src/Domain/Federation/BlockedActorPolicy.php`, `src/Domain/Federation/FollowPolicy.php` |
| No administrator route is part of the core contract | `src/Contract/Routes.php`; no admin route is declared |

## When to revisit

Revisit this ADR if both hosts need a shared, read-only directory of local
accounts that `LocalActorDirectory` cannot express, if they need a shared
session format, or if a product requirement for cross-host single sign-on
appears. A read-only directory capability can be added without moving
credentials or roles into the core. Authentication, password handling, session
storage, token issuance and administrator authority remain host work. Revisit
this ADR before adding any user, session, credential or role table to `src/`.

The legal data-controller question for a deployment is explicitly outside this
ADR. It should be answered in the deployment's privacy documentation, not
invented as a core table or a hidden authorization rule.
