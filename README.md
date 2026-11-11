# Aloha Social Core

The host-neutral core of Aloha Social: domain, federation, services, HTTP
contract and platform ports.

This repository is a **library**. It contains no server, no login page and no
process you could install; it is what both Aloha Social hosts run.

| Host | What it is | Status |
| --- | --- | --- |
| [`AlohaSocial/social`](https://github.com/AlohaSocial/social) | the Nextcloud app | exists, not yet consuming this package |
| `AlohaSocial/server` | the standalone server, installed by script or container | planned |
| this repository | the code both of them share | foundation |

The architecture and the reasons behind it are in
[`docs/adr/0001`](docs/adr/0001-shared-core-two-hosts.md).

## Status

Implemented and tested here:

| Area | What |
| --- | --- |
| Platform ports | config, clock, blob storage (local and in-memory), database (PDO), outbound HTTP (curl) |
| Domain | posts, visibility, post repositories (in-memory and SQL, same contract test) |
| Follow graph | followers per local account, in-memory and SQL repositories behind one contract test, inbox resolution |
| Inbound federation | signed `POST /inbox`, actor-key resolution over a port, `Follow` → edge + `Accept`, `Undo(Follow)`, replay guard |
| Discovery | ActivityStreams actor document with the published key, the followers `OrderedCollection` and the public outbox |
| Services | write/read/delete a post, timelines, followers, instance description |
| Federation | RSA key pairs, HTTP signatures (sign and verify), ActivityStreams `Create`/`Delete`, inbox fan-out, signed delivery jobs with backoff and dead letters |
| HTTP | request/response, parameterised router, middleware, kernel, one JSON error shape, route manifest and its JSON export |
| Security | AES-256-GCM secret box for private keys at rest |

Deliberately not here yet: inbound `Create`/`Delete`, WebFinger, local
follows, paged collections, media processing, the AT Protocol stack, the
Mastodon-compatible API, an admin UI, a migration runner, the Vue UI package
and the host adapters. The
[architecture document](docs/architecture.md#what-is-not-here-yet) lists them
with the seam they will attach to.

## Requirements

- PHP 8.3 or newer
- `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pdo` (runtime)
- `ext-pdo_sqlite` (tests)

## Quick start

```bash
composer install
composer check          # lint, host-neutrality guard, 378 tests
php bin/core-demo       # drives the core through real HTTP requests
php bin/export-routes   # the HTTP contract as JSON (14 routes)
```

The demo builds the in-memory assembly, writes a post, reads the timeline,
records a follower, shows the error shapes and shows that the inbox and the
actor routes answer 503 without a key or resolver — no database and no
network.

## Layout

```
src/
  Platform/        ports the hosts implement — no host code ever enters here
  Domain/          posts, followers, visibility, repositories, inbox resolution
  Service/         application behaviour and its validation
  Protocol/        ActivityPub documents, RSA keys, HTTP signatures
  Jobs/            durable queue contract, in-memory queue, worker
  Http/            request, response, router, middleware, kernel
  Api/             one handler per route
  Contract/        Routes::manifest() — the single HTTP contract
  Security/        encryption at rest
  Support/         time and id helpers
tests/             unit, contract and end-to-end tests
bin/               demo and route export
tools/             lint and the host-neutrality guard
docs/              index, architecture, guides, reference, operations, ADRs
```

## The three contracts

1. **HTTP** — [`src/Contract/Routes.php`](src/Contract/Routes.php) is the only
   place a route is declared. Both hosts must serve exactly this list; a host
   that drifts fails its parity test. See
   [ADR 0003](docs/adr/0003-http-contract.md).
2. **Platform** — everything the core needs from a host is one interface in
   `src/Platform/`. The Nextcloud and standalone adapters implement them; the
   core imports nothing from either. Enforced by `tools/check-core-boundary.php`
   on every run.
3. **Data** — the schema of a table lives next to its repository
   ([`PostSchema`](src/Domain/Post/PostSchema.php),
   [`FollowerSchema`](src/Domain/Federation/FollowerSchema.php)), so both
   databases have the same columns and a dump can move between them.

## Tests

```bash
composer test                                  # everything
php vendor/bin/phpunit tests/Http              # one directory
php vendor/bin/phpunit --filter HttpSignatureTest
```

The suite covers the platform adapters, the domain, both repository
implementations against one contract, the signature and ActivityPub
primitives, the queue's retry and dead-letter rules, routing and error
rendering, and end-to-end paths from HTTP through the queue to a signed
delivery to a real follower.

## Documentation

- [Documentation index](docs/README.md)
- [Architecture](docs/architecture.md)
- [Development](docs/development.md)
- [Routes](docs/reference/routes.md), [error codes](docs/reference/error-codes.md),
  [configuration](docs/reference/configuration.md)
- [Inbound federation](docs/guides/inbound-federation.md),
  [inbox activities](docs/reference/inbox-activities.md),
  [keys and clocks](docs/operations/keys-and-clocks.md)
- [Actor document](docs/reference/actor-document.md),
  [peer discovery](docs/guides/peer-discovery.md),
  [followers collection](docs/guides/followers-collection.md),
  [outbox](docs/guides/outbox.md)
- [ADR 0001 — one core, two hosts](docs/adr/0001-shared-core-two-hosts.md)
- [ADR 0002 — runtime](docs/adr/0002-runtime.md)
- [ADR 0003 — HTTP contract](docs/adr/0003-http-contract.md)
- [Contributing](CONTRIBUTING.md), [security](SECURITY.md),
  [code of conduct](CODE_OF_CONDUCT.md), [changelog](CHANGELOG.md)
- [Agent conventions](AGENTS.md)

## License

AGPL-3.0-or-later. Every file carries an SPDX header; the full text is in
[`LICENSE`](LICENSE).
