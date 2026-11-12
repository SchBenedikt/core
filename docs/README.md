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

## Guides

| Guide | Covers |
| --- | --- |
| [Platform ports](guides/platform-ports.md) | Every interface a host implements, and the contract tests behind them |
| [HTTP and routes](guides/http-and-routes.md) | The request lifecycle, adding a route, the error shape |
| [Using the core from a host](guides/host-integration.md) | Composer setup, ports, boot, HTTP mapping, worker, tests and what the core buys a host |
| [Nextcloud host integration](guides/nextcloud-host.md) | The concrete OCP adapters, URL prefixes, controller, migrations and rollout for `AlohaSocial/social` |
| [Migrating a Nextcloud installation](guides/migration-from-nextcloud.md) | Data mapping, neutral export format, the cutover validator, shadow runs and rollback for the existing app |
| [Shared UI and HostBridge](guides/shared-ui.md) | The Vue package boundary, bridge contract, package layout and extraction order |
| [Posts and timelines](guides/posts-and-timelines.md) | Validation, visibility, own and remote posts in the home timeline, boosts and deletes |
| [Media](guides/media.md) | Image upload, local originals and thumbnails, guarded remote attachments from federated posts, limits, failure reasons and cleanup |
| [Follow graph](guides/follow-graph.md) | Followers and following, inbox resolution, signed Follow/Undo delivery and the reverse lookup used by the home timeline |
| [Blocks](guides/blocks.md) | Per-user blocks, the block API, home-timeline filtering and the difference from the instance-wide policy |
| [Federation](guides/federation.md) | Actor ids, signatures, ActivityStreams documents, delivery |
| [AT Protocol publishing](guides/atproto.md) | The public text-only ATProto foundation, account/publisher ports, idempotent publication rows, limits and the external rest |
| [Mastodon-compatible API](guides/mastodon-api.md) | The disabled-by-default local subset, prefix, scopes, media upload/update/delete, pagination, idempotency and the external rest |
| [Inbound federation](guides/inbound-federation.md) | `POST /inbox` and `/inbox/shared`, verification order, Follow/Undo, Create/Delete/Announce and remote-post storage |
| [Shared inboxes](guides/shared-inboxes.md) | Actor-document discovery, the shared route, stored peer shared inboxes, delivery batching, fallback and migration |
| [Remote outbox backfill](guides/remote-backfill.md) | Bounded catch-up of a followed actor's public `Create`/`Announce` outbox, including historical media |
| [Remote threads](guides/remote-threads.md) | On-demand ancestors and direct replies, the thread cache, limits and cleanup |
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
| [Keys and clocks](operations/keys-and-clocks.md) | Key resolution, caching, clock skew and replay windows |
| [Roadmap](roadmap.md) | Done, next, later |
| [FAQ](faq.md) | Short answers to the questions that keep coming back |

Contributing rules are in [CONTRIBUTING](../CONTRIBUTING.md); security reports
go through [SECURITY](../SECURITY.md).
