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

## Guides

| Guide | Covers |
| --- | --- |
| [Platform ports](guides/platform-ports.md) | Every interface a host implements, and the contract tests behind them |
| [HTTP and routes](guides/http-and-routes.md) | The request lifecycle, adding a route, the error shape |
| [Posts and timelines](guides/posts-and-timelines.md) | Validation, visibility, what a timeline contains |
| [Follow graph](guides/follow-graph.md) | Followers, inbox resolution and why delivery is idempotent |
| [Federation](guides/federation.md) | Actor ids, signatures, ActivityStreams documents, delivery |
| [Inbound federation](guides/inbound-federation.md) | `POST /inbox`, verification order, Follow and Undo |
| [Peer discovery](guides/peer-discovery.md) | How a peer finds the actor, its key and the inbox |
| [WebFinger](guides/webfinger.md) | The JRD that resolves an `acct:` handle to the actor document |
| [Followers collection](guides/followers-collection.md) | The incoming `OrderedCollection` and what changes it |
| [Following collection](guides/following-collection.md) | The outgoing `OrderedCollection` and why it is separate from followers |
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
