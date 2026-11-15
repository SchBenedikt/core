# Documentation

Start with the [architecture](architecture.md); it explains the layers and the
one rule this repository has. Everything else goes deeper on one part.

## Concepts

| Document | Answers |
| --- | --- |
| [Architecture](architecture.md) | How the layers fit, how a request flows, what a port is, what is still missing |
| [ADR 0001 — one core, two hosts](adr/0001-shared-core-two-hosts.md) | Why this repository exists and how the three repositories work together |
| [ADR 0002 — runtime](adr/0002-runtime.md) | Why PHP, why components instead of a framework |
| [ADR 0003 — HTTP contract](adr/0003-http-contract.md) | Why one route manifest drives both hosts |
| [ADR 0004 — incremental migration and ownership](adr/0004-incremental-migration-and-ownership.md) | How existing data moves without changing federation identities, and which repository owns a feature |
| [ADR 0005 — shared UI](adr/0005-shared-ui.md) | The `@alohasocial/ui` package and its `HostBridge` boundary |
| [ADR 0006 — accounts, sessions and roles stay host-owned](adr/0006-host-owned-accounts.md) | The identity boundary between the core and both hosts: `Request::$userId`, scopes, `HostBridge`, roles and the standalone account model |
| [ADR 0007 — two protocols, one model](adr/0007-two-protocols-one-model.md) | Why ActivityPub and AT Protocol stay two wire protocols instead of becoming a third |
| [ADR 0008 — optional native PDS](adr/0008-native-pds.md) | Core-owned DID/PLC lifecycle, repository engine, security gates and the staged native PDS |
| [ADR 0009 — E2EE direct messages](adr/0009-e2ee-direct-messages.md) | Why remote DMs wait for MLS, what the server may store, and the threat model |

## Guides

| Guide | Covers |
| --- | --- |
| [Platform ports](guides/platform-ports.md) | Every interface a host implements, and the contract tests behind them |
| [HTTP and routes](guides/http-and-routes.md) | The request lifecycle, adding a route, the error shape |
| [The standalone server blueprint](guides/server-architecture.md) | How the server is built: processes, module layout, boot order, configuration, migrations, the AT Protocol linking flow, worker, security and the phase plan with acceptance checks |
| [Using the core from a host](guides/host-integration.md) | Composer setup, the core/UI compatibility matrix, ports, boot, HTTP mapping, worker, tests and what the core buys a host |
| [Nextcloud host integration](guides/nextcloud-host.md) | The concrete OCP adapters, URL prefixes, controller, migrations and rollout for `AlohaSocial/social` |
| [Migrating a Nextcloud installation](guides/migration-from-nextcloud.md) | Data mapping, neutral export format, the cutover validator, shadow runs and rollback for the existing app |
| [Shared UI and HostBridge](guides/shared-ui.md) | The extracted Vue package, versioned bridge contract, package layout, read/write screens and host wiring |
| [Posts and timelines](guides/posts-and-timelines.md) | Validation, visibility, replies, local/remote posts and boosts in the home timeline, and deletes |
| [Media](guides/media.md) | Image upload, local originals and thumbnails, guarded remote attachments from federated posts, limits, failure reasons and cleanup |
| [Follow graph](guides/follow-graph.md) | Followers and following, inbox resolution, signed Follow/Undo delivery and the reverse lookup used by the home timeline |
| [Blocks](guides/blocks.md) | Per-user blocks, the block API, home-timeline filtering and the difference from the instance-wide policy |
| [Federation](guides/federation.md) | Actor ids, signatures, ActivityStreams documents, delivery |
| [AT Protocol publishing and inbound sync](guides/atproto.md) | Records, facets, replies, media embeds, likes/reposts, profile, the AppView timeline read, replay-safe relay/Jetstream import, cursors/tombstones, publication rows, OAuth authorization-code/PAR/revocation, limits and the external rest |
| [AT Protocol repository primitives](guides/atproto-repository.md) | CID, canonical DAG-CBOR, CAR, MST, signed commits, compatibility vectors and the current native-PDS boundary |
| [Two protocols, one local model](guides/dual-protocol.md) | How ActivityPub and AT Protocol share one local post, what is and is not combined, and why this is not a new protocol |
| [Mastodon-compatible API](guides/mastodon-api.md) | The disabled-by-default local subset, instance metadata/statistics, prefix, scopes, private lists and list timelines, local boosts/reblogs, reply context, local follow/unfollow and relationship reads, local notification listing with dismiss/unread state, media upload/update/delete, pagination, idempotency and the external rest |
| [Inbound federation](guides/inbound-federation.md) | `POST /inbox` and `/inbox/shared`, verification order, Follow/Undo, Create/Delete/Announce and remote-post storage |
| [Shared inboxes](guides/shared-inboxes.md) | Actor-document discovery, the shared route, stored peer shared inboxes, delivery batching, fallback and migration |
| [Remote outbox backfill](guides/remote-backfill.md) | Bounded catch-up of a followed actor's public `Create`/`Announce` outbox, including historical media |
| [Remote threads](guides/remote-threads.md) | On-demand ancestors and direct replies, the thread cache and limits |
| [Retention cleanup](guides/retention-cleanup.md) | The `cleanup` job for orphan media, read notifications and thread caches: payload, limits, idempotence, failure reasons and host scheduling |
| [Peer discovery](guides/peer-discovery.md) | How a peer finds the actor, its key and the inbox |
| [WebFinger](guides/webfinger.md) | The JRD that resolves an `acct:` handle to the actor document |
| [Followers collection](guides/followers-collection.md) | The incoming `OrderedCollection` and what changes it |
| [Following collection](guides/following-collection.md) | The outgoing `OrderedCollection`, the local Follow/Undo endpoint and why it is separate from followers |
| [Outbox](guides/outbox.md) | The public post collection, its `Create` items and how peers reconcile them |
| [Jobs and delivery](guides/jobs-and-delivery.md) | The queue contract, retries, backoff and dead letters |
| [Database](guides/database.md) | The SQL port, schemas, timestamps and transactions |
| [Storage](guides/storage.md) | Blob paths, the local store and the in-memory store |
| [Security](guides/security.md) | Signing, verification and encryption at rest |
| [Testing](guides/testing.md) | How the suite is organised and how to extend it |

## Reference

| Reference | Content |
| --- | --- |
| [Routes](reference/routes.md) | Every endpoint with auth, rate limit, request and response |
| [Inbox activities](reference/inbox-activities.md) | Supported activity types and every refusal with its status |
| [Actor document](reference/actor-document.md) | Every field of `GET /@{handle}` and its invariants |
| [Error codes](reference/error-codes.md) | Every `error.code`, its status and when it appears |
| [Configuration](reference/configuration.md) | The configuration keys the core reads, and its limits |

## Operating

| Document | Content |
| --- | --- |
| [The standalone host](operations/standalone-host.md) | What the future server has to provide and in which order it boots |
| [Release artifacts](operations/release.md) | Tag/version rule, Composer archive and npm tarball checks, publication steps and the current credential blocker |
| [Keys and clocks](operations/keys-and-clocks.md) | Key resolution, caching, clock skew and replay windows |
| [Roadmap](roadmap.md) | Goal, current status, milestones and the next steps |
| [FAQ](faq.md) | Short answers to the questions that keep coming back |

Contributing rules are in [CONTRIBUTING](../CONTRIBUTING.md); security reports
go through [SECURITY](../SECURITY.md).
