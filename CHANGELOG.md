# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Durable queue and replay log: `PdoJobQueue` and `PdoActivityLog` implement
  the existing ports over `Platform\Db\Connection`, with `JobSchema` and
  `ActivityLogSchema` as the canonical DDL. A job survives a fresh connection;
  reserve/complete/fail, retry, dead-letter and replay rejection are tested
  against a SQLite file. `Application::forHost()` now has production-ready
  adapters instead of requiring every host to build its own.
- Host integration: `Application::forHost()` wires the SQL repositories and a
  host's ports into the same kernel, worker and services as the reference
  assembly. The host integration and Nextcloud guides cover Composer setup,
  adapters, URL prefixes, HTTP mapping, migrations and rollout.
- Discovery: `GET /@{handle}` serves the ActivityStreams actor document with
  `preferredUsername`, `name`, `inbox`, `outbox`, `followers` and a
  `publicKey` whose id is exactly the key id inbound verification resolves;
  `GET /@{handle}/followers` serves the `OrderedCollection` of real follower
  actor ids. Both are disabled (503) without a signing key and a local actor
  directory.
- Outbox: `GET /@{handle}/outbox` serves the `OrderedCollection` of the
  account's public posts as `Create` activities, newest first. Its id is
  exactly the `outbox` URL the actor document advertises, and every item is
  the same document outbound delivery sends.
- WebFinger: `GET /.well-known/webfinger?resource=acct:{handle}@{host}`
  resolves a local handle to the actor document URL and serves it as
  `application/jrd+json` with a single `self` link of type
  `application/activity+json`. The configured host is enforced; foreign hosts
  and unknown handles share one 404 without echoing either.
- Following: `GET /@{handle}/following` serves the `OrderedCollection` of the
  actors a local account follows, newest first. It reads a separate outgoing
  follow repository (`social_following`), so it is not a copy of the
  followers table; the collection id is exactly the `following` URL the actor
  document advertises.
- Local following: `POST /api/v1/following` stores the outgoing edge and
  delivers a signed `Follow` to the supplied inbox; `DELETE
  /api/v1/following/{id}` delivers an `Undo` and removes the edge only after
  the peer accepted it. A refused Follow is rolled back, a refused Undo keeps
  the follow retryable. The outgoing schema now stores the inbox. 18 routes.
- Inbound `POST /inbox`: signature verification against an actor resolved by
  key id, `Follow` → follower edge + queued `Accept`, `Undo(Follow)`, and a
  replay guard. The endpoint stays at 503 without a configured resolver.
- `ActorResolver` / `HttpActorResolver`, `RemoteActor`, `ActivityLog` /
  `InMemoryActivityLog` and `FollowerRepository::removeByActor` as the ports
  and domain pieces behind the inbox.
- Follow graph: `Follower` entity, in-memory and SQL repositories behind one
  contract test, `FollowerSchema`, and `FollowerInboxResolver`.
- `FollowerService` with idempotent follow, per-account isolation and cursor
  pagination.
- Routes `followers.list`, `followers.follow`, `followers.unfollow` (the
  manifest now has 18 routes).
- End-to-end delivery to real followers: a followed actor's inbox receives the
  signed `Create` of the next post.
- Documentation: concept guides, a route reference, an error-code reference, a
  configuration reference, an operations checklist, a roadmap, an FAQ and
  contributor/security/conduct documents.

### Fixed

- A post with `followers` visibility was readable by anyone who knew its id.
  Single-post reads now apply visibility and answer 404 to non-authors.

### Changed

- The reference assembly federates to the stored follow graph when an HTTP
  client and a signing key are provided; without them it reports no audience,
  so no delivery is queued that could not be signed.

## [0.1.0] - 2026-11-11

### Added

- Platform ports: clock, configuration, blob storage, SQL connection, HTTP
  client, each with an in-memory and a production adapter.
- Posts and visibility, soft deletion, public and home timelines with stable
  cursor pagination.
- ActivityPub: RSA key pairs, HTTP signatures (sign and verify), `Create` and
  `Delete` documents, audience mapping.
- Delivery queue with retry backoff, dead letters and a worker; signed POSTs.
- HTTP kernel, parameterised router, middleware, one JSON error shape.
- A single route manifest with a JSON export, and a host-neutrality guard.
- AES-256-GCM secret box.
- 284 tests; CI on PHP 8.3, 8.4 and 8.5.
