# The standalone host

This repository is the library. A server without Nextcloud is a separate
repository (`AlohaSocial/server`, not created yet) that depends on this package
and provides everything a process needs.

This document is the checklist that host is built against.

## Boot order

1. **Configuration** — read the environment, build a `Config`.
2. **Database** — connect PDO, wrap it in `PdoConnection`, run the schema
   statements (`PostSchema`, `FollowerSchema`) from the migration step.
3. **Clock and ids** — `SystemClock`, `RandomIdGenerator`.
4. **Keys** — load the instance key pair; seal it at rest with `SecretBox`.
5. **HTTP client** — `CurlHttpClient` with a timeout and a user agent.
6. **Repositories** — `PdoPostRepository`, `PdoFollowerRepository`.
7. **Services** — `PostService` with a `FollowerInboxResolver`,
   `TimelineService`, `FollowerService`.
8. **Inbound** — `HttpActorResolver` over the HTTP client, a durable
   `ActivityLog`, and `InboxService`. Without all three the inbox answers 503.
9. **Handlers and kernel** — register every handler from the route manifest and
   fail at boot if one is missing.
10. **Worker** — a process or cron entry calling `JobWorker::runOnce()`.

Steps 1–8 are what `Application::inMemory()` does with in-memory adapters; the
server repeats it with real ones.

## What the host owns

| Concern | Why it is not in the core |
| --- | --- |
| Authentication, sessions, password reset | Different in Nextcloud and standalone |
| HTTP kernel and routing to the entry point | The core exposes `Kernel`, not a server |
| Templates and the admin UI | Presentation is host business |
| Migrations | The mechanism differs per host; schemas are shared |
| Media processing | ffmpeg and image libraries are deployment choices |
| Scheduler and queue persistence | A systemd timer, cron or a queue service |
| Durable `ActivityLog` | A table or Redis shared by all workers; the in-memory one is per process |
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
