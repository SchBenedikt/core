# The standalone host

This repository is the library. A server without Nextcloud is a separate
repository (`AlohaSocial/server`, not created yet) that depends on this package
and provides everything a process needs.

This document is the checklist that host is built against. The full build
blueprint — process topology, module layout, configuration, migrations, the AT
Protocol linking flow and a phase plan with acceptance checks — is in
[the server blueprint](../guides/server-architecture.md).

## Boot order

1. **Configuration** — read the environment, build a `Config`. The optional
   `social/instance.*` metadata keys feed both instance endpoints; unset keys
   use their documented defaults, and no instance row or migration is needed.
2. **Database** — connect PDO, wrap it in `PdoConnection`, run the schema
   statements    (`PostSchema`, `PollSchema`, `FavouriteSchema`, `BookmarkSchema`,
   `BoostSchema`, `NotificationSchema`, `AttachmentSchema`, `FollowerSchema`,
   `FollowingSchema`, `RemotePostSchema`, `RemoteThreadSchema`, `BlockSchema`,
   `MuteSchema`, `AccountListSchema`, `AtprotoPublicationSchema`, `JobSchema`,
   `ActivityLogSchema`) from the migration step.
   `social_poll`/`social_poll_option` are needed whenever status reads or
   writes are exposed through the Mastodon compatibility surface, because
   `PdoPostRepository` reads polls for every status;    `social_favourite`, `social_bookmark`, `social_boost` and
   `social_notification` are needed only when favourite/unfavourite,
   bookmark/unbookmark and its list, boost/reblog and notification listing,
   dismiss/clear and unread counts are
   exposed. `social_account_list` and `social_account_list_member` are needed
   only for the Mastodon list routes and the exclusive home filter.
   `social_followed_hashtag` is needed only for the neutral followed-tag routes
   and `timelines.followed_tags`; the timeline also reads
   `social_post_hashtag`. There is no backfill. Existing listing-only `social_notification` tables need the
   nullable `read_at` column and the unread index from
   `NotificationSchema`; old rows remain unread. Local search needs
   no additional table: it uses `social_post` and the host's account
   directory. Instance metadata/statistics also add no table or migration;
   they read configuration and the existing stores through optional count
   capabilities.
   Existing `social_follower` tables also need the `is_local`
   column before local follow/unfollow is exposed; it defaults old rows to
   remote (`0`) and needs no backfill.    Existing hosts must add
   `social_poll`/`social_poll_option` from `PollSchema` before a status read
   touches the poll lookup or a `poll` is sent; the old rows need no backfill.
   For notification read state, an existing table needs:

   ```sql
   ALTER TABLE social_notification ADD COLUMN read_at VARCHAR(20) NULL;
   CREATE INDEX social_notification_unread
     ON social_notification (recipient_id, read_at, created_at, id);
   ```

   Old notification rows become unread; no row is deleted and no backfill is
   needed.
3. **Clock and ids** — `SystemClock`, `RandomIdGenerator`.
4. **Keys** — load the instance key pair; seal it at rest with `SecretBox`.
5. **HTTP client** — `CurlHttpClient` with a timeout and a user agent.
6. **Repositories** — `PdoPostRepository`, `PdoAttachmentRepository`,
   `PdoFollowerRepository`, `PdoFollowingRepository`,
   `PdoRemotePostRepository`, `PdoRemoteThreadRepository`,
   `PdoBlockRepository`, `PdoFavouriteRepository`, `PdoBookmarkRepository`,
   `PdoMuteRepository`, `PdoBoostRepository`, `PdoPollVoteRepository`,
   `PdoAccountListRepository`, `PdoNotificationRepository` and
   `PdoAtprotoPublicationRepository` when the matching feature is exposed, and
   the combined `PdoHomeTimelineRepository`. The PDO post and
   remote-post repositories already implement `PostCount` and
   `RemoteDomainCount`; a custom local directory should implement
   `LocalActorCount` if the instance stats should include its accounts,
   otherwise `user_count` stays `0`.
7. **Services** — `PostService` with a `FollowerInboxResolver`,
   `TimelineService`, `FollowerService`, `FollowingService` with the delivery
   handler, `BlockService`, `BoostService`, `MuteService`,
   `NotificationService`, `LocalRelationshipService`, `AccountListService` for
   private lists, `MastodonService` for the optional compatibility prefix, the
   AT Protocol publish/session services, and `MediaService` with a `BlobStore`
   and an `ImageProcessor`.
8. **Inbound** — `HttpActorResolver` over the HTTP client, `PdoActivityLog`,
   `InboxService` and, when `social_remote_post` exists, a `RemotePostRepository`
   for signed Create/Delete/Announce. Both `/inbox` and `/inbox/shared` use
   that service. The home timeline additionally needs `social_block` for the
   per-user filter. Without the resolver, client and key, both inbox routes
   answer 503.
9. **Handlers and kernel** — register every handler from the route manifest and
   fail at boot if one is missing.
10. **Worker** — `PdoJobQueue` plus a process or cron entry calling
    `JobWorker::runOnce()`. The same worker handles the core `cleanup` job;
    schedule a separate daily or hourly enqueue of `JobType::CLEANUP` with the
    host's retention values. See
    [retention cleanup](../guides/retention-cleanup.md).

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
| Roles, groups and instance-administrator rights | Host permission models differ; the core has no role table or admin route ([ADR 0006](../adr/0006-host-owned-accounts.md)) |
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
wait, not a worker. Enqueue the cleanup job on a slower schedule than the
worker (for example once a day); it is bounded by `limit` per kind, so a large
instance can run it more often or raise the limit deliberately. A dry run is a
one-off service call, not a queue job.

## The installer

The interactive installer belongs to the server repository. It should:

1. check PHP version and extensions;
2. ask for the instance URL and an admin account;
3. create the database schema;
4. generate and seal the instance key;
5. write configuration outside the web root;
6. print the exact cron line and a health-check URL, including the cleanup
   enqueue line if the host does not schedule it in application code.

Nothing in this repository assumes an installer, and the core must keep working
when a host is configured by hand.

## Docker

A container image is a thin wrapper around the same steps: an entrypoint that
runs the migrations once and then starts the web process and the worker. The
image must not bake configuration or keys into a layer.

## Status

`AlohaSocial/server` does not exist yet; this page is the build checklist for
milestone M4 in the [roadmap](../roadmap.md). The core's job is to make it a
small repository, not a second application. Everything in the boot order above
is available in the core today except the host's own accounts/sessions and the
migration runner. [ADR 0006](../adr/0006-host-owned-accounts.md) (accepted)
keeps accounts, sessions, credentials, roles and administrator rights
host-owned; the standalone implements them against its own schema.

When created, the server pins core `0.7.x` and `@alohasocial/ui` `0.7.x` with
HostBridge contract `1`; the server itself has no published version yet. See
the [compatibility matrix](../guides/host-integration.md#compatibility).
