# AGENTS.md

`alohasocial/core` is the host-neutral core of Aloha Social. It is a library:
domain, services, federation protocol, HTTP contract and platform ports. The
Nextcloud app and the standalone server are thin hosts around it.

Read `docs/architecture.md` and `docs/adr/0001-shared-core-two-hosts.md` before
changing a subsystem.

## The one rule

**Nothing in `src/` may reference a host.** No `OCP\`, no `OCA\`, no
`nextcloud/*` dependency, no `$_SERVER`/session/global state. `composer
check:boundary` enforces it and must stay green.

If a feature needs something from the host, add a port in `src/Platform/` and
implement it in the host. A comment may *name* a host to explain an adapter;
code may not call one.

## Commands

```sh
composer check                     # lint + boundary + tests (the gate)
composer test
php vendor/bin/phpunit tests/Http
php vendor/bin/phpunit --filter HttpSignatureTest
php bin/core-demo
php bin/export-routes
```

PHP 8.3+ is required; see `docs/development.md` for a rootless setup. The
full documentation index is `docs/README.md`.

## Conventions

- `declare(strict_types=1)` and an SPDX header in every file
  (`SPDX-FileCopyrightText: 2026 Aloha Social contributors`,
  `SPDX-License-Identifier: AGPL-3.0-or-later`). Files that cannot carry a
  header go into `REUSE.toml`.
- Value objects are `final readonly`; a change returns a new instance.
- Business rules live in `src/Service`; handlers only map input and output.
- A route exists only in `src/Contract/Routes.php`.
- Every new behaviour needs a test that fails without it. Every new port
  implementation must pass the existing contract test (`BlobStoreTestCase`,
  `PostRepositoryTestCase`).
- Conventional Commits with a scope: `feat(posts): …`, `fix(signature): …`.
- Commits carry no tool or AI attribution trailers; the author is the human
  who submits the work.
- No dead code, no unused imports, no commented-out blocks.
- Comments explain *why* — invariants, security decisions, workarounds.

## Security

`Protocol/ActivityPub/HttpSignature` and `Security/OpenSslSecretBox` are the
security boundary. Never weaken a check to make a test or a peer pass. A
refusal always has a stable `reason`; keep it stable, tests and logs depend on
it.

Do not log request bodies, private keys or sealed payloads.

## Current scope

Implemented: posts and timelines, the follow graph, inbound and outbound
ActivityPub (signed `POST /inbox`, `Follow`/`Undo`, `Accept`), the actor
document, followers collection and outbox, the HTTP contract and the platform
ports. Not implemented: inbound `Create`/`Delete`, WebFinger, local follows,
media. See `docs/roadmap.md` before starting a large feature.

## What not to do

- Do not add a dependency that a host would have to adopt (a framework, an
  ORM, a second HTTP stack).
- Do not add a `Platform` method for something only one host has; that is the
  host's business.
- Do not move the Vue UI or the Nextcloud controllers into this repository
  before ADR 0001's follow-ups are decided.
