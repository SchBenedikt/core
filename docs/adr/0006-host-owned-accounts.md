# ADR 0006 — Accounts stay host-owned

- Status: proposed
- Date: 2026-11-13

## Context

`Application::forHost()` builds the kernel. Every handler reads
`Request::$userId`, which the host sets after authenticating the caller; a null
user is anonymous, and `Request::$scopes` carries the token scopes the host
resolved for the optional Mastodon prefix.

Nextcloud supplies users, sessions, password reset, CSRF and bearer tokens from
its own runtime. The future standalone host must supply the same from its own
tables. ADR 0001 listed "users, sessions" among the ports to add when the
standalone gets its own accounts, and the roadmap's M4 milestone reopens the
question: does the core need a `Platform\Identity` port and a `User` domain?

## Decision

No. Accounts, credentials, sessions, password reset, token issuance and the
login UI stay host-owned. The core keeps the seam it already has:

- `Request::$userId` is the authenticated local account id. The host
  authenticates before it constructs the request.
- `Request::$scopes` is the host-resolved token scope list for compatibility
  routes; the core checks it, it does not issue it.
- `Domain\Federation\LocalActorDirectory` is the read-side interface for
  presenting local accounts. It is a domain read interface, not an identity
  provider.
- The core stores no passwords, sessions, reset tokens or user profiles and
  defines no login flow.

The standalone host implements accounts and sessions with its own schema and
passes the resulting local user id into the request, exactly as Nextcloud does.

## Why not a `User` domain or an `Identity` port

- Authentication differs more between the hosts than any other concern. A port
  would either be a lowest common denominator or force one host's session model
  on the other.
- A core user table would make the core an application and contradict ADR
  0001's library boundary.
- The kernel already accepts an authenticated id, so a second abstraction would
  not remove a line from either host.
- The standalone can implement accounts with its own storage, password hashing,
  rate limiting and reset flow without a core migration.

## Consequences

**Good**

- The core stays a library; no breaking port addition and no user table.
- Each host keeps its native authentication and can change it independently.
- The `userId` contract is already tested through the kernel.

**Costs**

- Neither host can share an account implementation; single sign-on would be a
  host integration, not a core feature.
- The standalone host owns password hashing, session storage, reset tokens,
  token issuance and their documentation.
- A future shared account directory must be designed as a read-only domain
  interface, not as an authentication port.

## When to revisit

If both hosts need to share a session format, or a directory of local accounts
that `LocalActorDirectory` cannot express, add a read-only directory interface.
A login flow, credentials and session storage remain host work. Revisit this
ADR before adding any user table to `src/`.
