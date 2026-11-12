# Roadmap

Status of the core, not a promise about dates.

## Done

- Platform ports: clock, config, blob storage, SQL, HTTP client — with in-memory
  and production adapters.
- Posts: validation, visibility, soft deletion, single-post access control.
- Timelines: public and home, stable cursor pagination.
- ActivityPub: RSA keys, HTTP signatures (sign and verify), `Create`/`Delete`
  documents, audience mapping.
- Delivery: durable queue contract, retries with backoff, dead letters,
  signed POSTs, idempotent activity ids. `PdoJobQueue` and `PdoActivityLog`
  provide the durable SQL implementations.
- Follow graph: follower storage (in-memory and SQL), inbox resolution, three
  endpoints, isolated per account.
- Inbound federation: `POST /inbox` with signature verification, actor-key
  resolution over a port, `Follow` → edge + `Accept`, `Undo(Follow)`, replay
  guard, and signed `Create`/`Delete`/`Announce` from followed remote actors.
- Remote posts and boosts: `RemotePost`/`RemotePostKind`, a canonical
  `RemotePostSchema` (`social_remote_post`), in-memory and PDO repositories,
  activity-id deduplication and soft deletion. Announce rows are boosts, not
  standalone posts.
- Follow-based home timeline: `HomeTimelineRepository` combines the reader's
  own local posts with remote posts/boosts from the outgoing follow graph and
  applies one cursor and limit after sorting.
- Inbound content authorization: an actor must be followed by at least one
  local account, an optional host `BlockedActorPolicy` can refuse an actor,
  and an object/Delete ownership mismatch is refused without changing state.
- Discovery: the ActivityStreams actor document with its published key, and
  the followers `OrderedCollection` read from the follower repository.
- Outbox: the public `OrderedCollection` of an account's public posts as
  `Create` activities, read from the same post repository the local API uses.
- Following: the public `OrderedCollection` of the actors a local account
  follows, read from a separate outgoing follow repository.
- Paged actor collections: followers, following and outbox advertise a
  `first` page and serve `OrderedCollectionPage` documents with `partOf`,
  `next`, `prev` and a bounded page size, backed by repository page queries.
- Local following: `POST`/`DELETE /api/v1/following` writes the edge, delivers
  a signed `Follow`/`Undo`, rolls back a refused Follow and keeps a refused
  Undo retryable.
- WebFinger: `GET /.well-known/webfinger` resolves a local `acct:` handle to
  the actor document URL, with the configured host enforced.
- HTTP: one route manifest, generic kernel, one error shape, JSON export.
- Security: AES-256-GCM secret box.
- Documentation and CI across PHP 8.3, 8.4, 8.5.
- Migration and ownership contract: [ADR 0004](adr/0004-incremental-migration-and-ownership.md),
  the [Nextcloud migration guide](guides/migration-from-nextcloud.md), the
  read-only cutover validator (`bin/validate-cutover`) and the shared UI
  contract in [ADR 0005](adr/0005-shared-ui.md).

## Next

| Item | Seam |
| --- | --- |
| Nextcloud posts/follows import and actor-URL parity | `AlohaSocial/social` host migration, using the core schemas and `bin/validate-cutover` |
| Cutover of posts/timelines, actor surface, follow delivery and inbox | one host feature flag at a time, after the identity gate |
| `@alohasocial/ui` extraction and the Nextcloud `HostBridge` | `ui/`, then `AlohaSocial/social` |
| Media pipeline (upload, resize, alt text) | `BlobStore` + a new service |
| Migration runner for the standalone | host concern, schemas already exist |

## Later

- AT Protocol stack, moved out of the Nextcloud app only after the core owns
  the shared protocol behaviour.
- Mastodon-compatible API as additional routes over the same services.
- Standalone `HostBridge` once `AlohaSocial/server` has its auth flow.
- Remote-post backfill, thread/context fetching and media fetching (the
  current implementation only stores embedded objects delivered to the inbox).
- Shared inboxes and delivery batching.
- Per-user block management; the current core only exposes an optional
  instance-wide `BlockedActorPolicy` seam.
- OpenAPI generation from the route manifest.

## Explicitly not planned

- A rewrite in another language.
- A "fake Nextcloud" shim as a permanent architecture (see
  [ADR 0001](adr/0001-shared-core-two-hosts.md)).
- Storing configuration or keys in this repository.
