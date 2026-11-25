# ADR 0012 — Host-owned Mastodon event streaming

- Status: accepted
- Date: 2026-11-25

## Context

Mastodon clients use streaming updates for timelines and notifications. Core
must not keep a process-global event bus: requests and workers can run in
different PHP processes, and the Nextcloud and standalone hosts own different
storage and lifecycle boundaries. A stream also carries private home,
notification, list, and direct-message data, so a topic name alone is not an
authorization boundary.

## Decision

Core defines the host-neutral `EventBroker` port and event/subscription value
objects under `Platform/Events`. Hosts provide durable storage and transport.
The broker returns events strictly after the supplied cursor, in a stable
order, and must filter each result for the authenticated account and channel
visibility before returning it. A host may use its shared database or another
durable broker; an in-memory singleton is not a supported implementation.

The first HTTP transport is Server-Sent Events (SSE) over the configured
Mastodon API prefix at `/streaming`, using the `stream` query parameter and
standard `id`, `event`, and `data` fields. The server sends periodic comments
as keepalives and supports `Last-Event-ID` for reconnects. REST timeline and
notification endpoints remain the resynchronization path after a cursor has
expired. WebSocket support is a separate transport and is not implied by SSE.

The host authenticates the bearer token before opening a stream and checks
scopes per channel: status-reading for timelines, notification-reading for
notifications, and the corresponding list/direct visibility checks. The
host must revalidate token/account state while a connection is open, enforce
connection and event limits, and close streams promptly on revocation,
disable, shutdown, or backpressure. Public events must not contain private
recipient data. User and notification streams are always recipient-scoped.

The `Response` contract accepts a lazy iterable of string chunks so a host can
flush frames without buffering an entire connection. Ordinary JSON and text
responses keep their existing body behavior.

## Consequences

- Core stays independent of SAPI, Nextcloud, database, and transport details.
- Each host must implement durable cursors, visibility filtering, and stream
  lifecycle controls before advertising the streaming endpoint.
- A broker outage should close the stream and let the client resynchronize
  over REST; the host must not fabricate a successful event.
