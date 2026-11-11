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
  signed POSTs, idempotent activity ids.
- Follow graph: follower storage (in-memory and SQL), inbox resolution, three
  endpoints, isolated per account.
- Inbound federation: `POST /inbox` with signature verification, actor-key
  resolution over a port, `Follow` → edge + `Accept`, `Undo(Follow)`, replay
  guard.
- HTTP: one route manifest, generic kernel, one error shape, JSON export.
- Security: AES-256-GCM secret box.
- Documentation and CI across PHP 8.3, 8.4, 8.5.

## Next

| Item | Seam |
| --- | --- |
| Inbound `Create`/`Delete`/`Announce` and fetching remote posts | `InboxService` + a remote post store |
| Actor document and `followers`/`following` collections | new `Api` handlers |
| WebFinger | host routing + a small handler |
| Local follows and a real home timeline | `Domain\Federation` + a local follow table |
| Media pipeline (upload, resize, alt text) | `BlobStore` + a new service |
| Durable queue adapter | `Jobs\JobQueue` |
| Migration runner for the standalone | host concern, schemas already exist |

## Later

- AT Protocol stack, moved out of the Nextcloud app.
- Mastodon-compatible API as additional routes over the same services.
- `@alohasocial/ui` with a host bridge, so the Vue front end is shared too.
- Shared inboxes and delivery batching.
- OpenAPI generation from the route manifest.

## Explicitly not planned

- A rewrite in another language.
- A "fake Nextcloud" shim as a permanent architecture (see
  [ADR 0001](adr/0001-shared-core-two-hosts.md)).
- Storing configuration or keys in this repository.
