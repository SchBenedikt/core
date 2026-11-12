# The standalone host

This repository is the library. A server without Nextcloud is a separate
repository (`AlohaSocial/server`, not created yet) that depends on this package
and provides everything a process needs.

This document is the checklist that host is built against.

## Boot order

1. **Configuration** — read the environment, build a `Config`.
2. **Database** — connect PDO, wrap it in `PdoConnection`, run the schema
   statements (`PostSchema`, `PollSchema`, `FavouriteSchema`,
   `NotificationSchema`, `AttachmentSchema`, `FollowerSchema`,
   `FollowingSchema`, `RemotePostSchema`, `RemoteThreadSchema`, `BlockSchema`,
   `JobSchema`, `ActivityLogSchema`) from the migration step.
   `social_poll`/`social_poll_option` are needed whenever status reads or
   writes are exposed through the Mastodon compatibility surface, because
   `PdoPostRepository` reads polls for every status; `social_favourite` and
   `social_notification` are needed only when favourite/unfavourite and
   notification listing are exposed. There is no backfill. Local search needs
   no additional table: it uses `social_post` and the host's account
   directory.
   Existing `social_follower` tables also need the `is_local`
   column before local follow/unfollow is exposed; it defaults old rows to
   remote (`0`) and needs no backfill. Existing hosts must add
   `social_poll`/`social_poll_option` from `PollSchema` before a status read
   touches the poll lookup or a `poll` is sent; the old rows need no backfill.
3. **Clock and ids** — `SystemClock`, `RandomIdGenerator`.
4. **Keys** — load the instance key pair; seal it at rest with `SecretBox`.
5. **HTTP client** — `CurlHttpClient` with a timeout and a user agent.
6. **Repositories** — `PdoPostRepository`, `PdoAttachmentRepository`,
   `PdoFollowerRepository`, `PdoFollowingRepository`,
   `PdoRemotePostRepository`, `PdoBlockRepository`, `PdoFavouriteRepository`
   and `PdoNotificationRepository` when local favourites and notifications
   are exposed, and the combined `PdoHomeTimelineRepository`.
7. **Services** — `PostService` with a `FollowerInboxResolver`,
   `TimelineService`, `FollowerService`, `FollowingService` with the delivery
   handler, `BlockService`, and `MediaService` with a `BlobStore` and an
   `ImageProcessor`.
8. **Inbound** — `HttpActorResolver` over the HTTP client, `PdoActivityLog`,
   `InboxService` and, when `social_remote_post` exists, a `RemotePostRepository`
   for signed Create/Delete/Announce. Both `/inbox` and `/inbox/shared` use
   that service. The home timeline additionally needs `social_block` for the
   per-user filter. Without the resolver, client and key, both inbox routes
   answer 503.
9. **Handlers and kernel** — register every handler from the route manifest and
   fail at boot if one is missing.
10. **Worker** — `PdoJobQueue` plus a process or cron entry calling
    `JobWorker::runOnce()`.

Steps 1–8 are what `Application::inMemory()` does with in-memory adapters; the
server repeats it with real ones. The reusable production assembly is
`Application::forHost()`: it takes the host's ports, builds the PDO
repositories and returns the kernel, worker and services. The durable queue and
replay log are part of the core: construct `PdoJobQueue` and `PdoActivityLog`
over the same connection and pass them to `forHost()`. See
[using the core from a host](../guides/host-integration.md) for the exact call
and the port table.

The shared Vue page mounts through a server `HostBridge`, not through a
Nextcloud compatibility layer. The bridge contract and the extraction order
are in [ADR 0005](../adr/0005-shared-ui.md) and the
[shared UI guide](../guides/shared-ui.md).

## What the host owns

| Concern | Why it is not in the core |
| --- | --- |
| Authentication, sessions, password reset | Different in Nextcloud and standalone |
| HTTP kernel and routing to the entry point | The core exposes `Kernel`, not a server |
| Templates and the admin UI | Presentation is host business |
| Migrations | The mechanism differs per host; schemas are shared |
| Media processing beyond local GD | The core ships a complete GD adapter; a host may replace `ImageProcessor` with ffmpeg, ImageMagick or libvips |
| Scheduler and worker supervision | A systemd timer, cron or a queue service; `PdoJobQueue` supplies the storage |
| TLS, proxy headers, rate limiting | Deployment, not application logic |

## Scheduler

`runOnce()` is cheap when there is nothing due. Two acceptable setups:

- cron, every minute: `php bin/worker.php`;
- a systemd service restarting the loop on exit.

The loop must sleep between empty batches; a hot loop with no jobs is a busy
wait, not a worker.

## The installer

The interactive installer belongs to the server repository. It should:

1. check PHP version and extensions;
2. ask for the instance URL and an admin account;
3. create the database schema;
4. generate and seal the instance key;
5. write configuration outside the web root;
6. print the exact cron line and a health-check URL.

Nothing in this repository assumes an installer, and the core must keep working
when a host is configured by hand.

## Docker

A container image is a thin wrapper around the same steps: an entrypoint that
runs the migrations once and then starts the web process and the worker. The
image must not bake configuration or keys into a layer.

## Status

None of this exists yet. The core's job is to make it a small repository, not a
second application.
