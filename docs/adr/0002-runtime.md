# ADR 0002 — Runtime and libraries

- Status: accepted
- Date: 2026-11-11

## Context

The standalone server needs an HTTP kernel, a database layer, a console, a
mailer, a scheduler and a place to put an installer. The core must run inside
Nextcloud, where PHP is given, and inside that server.

## Decision

**PHP stays, for both.** The standalone host composes Symfony components and
Doctrine DBAL; the core itself depends on almost nothing.

### Why PHP

- The behaviour worth keeping is PHP: HTTP signatures, the AT Protocol stack,
  the delivery queue, the service layer. A rewrite in Node or Go would
  reimplement it and would still have to match it byte for byte in federation.
- Both hosts then run the *same* files. That is the point of ADR 0001.
- Nextcloud is PHP; leaving PHP would guarantee two implementations forever.

### Why components, not a framework

The standalone host will use Symfony's HttpFoundation, Routing, DI, Console,
HttpClient, Cache, Lock, RateLimiter, Mailer and Validator, plus Doctrine DBAL
and Migrations. These are individually adoptable, documented and replaceable.
Laravel is rejected: its Eloquent models and its queue/scheduler would replace
the hand-written `*Request` query classes the app is built on, which is a
rewrite in disguise.

### Why the core itself has almost no dependencies

`composer.json` requires `psr/log` and PHP extensions. Everything else — the
database port, the HTTP client port, the queue — is an interface the host
satisfies. A core that depended on Symfony would drag Symfony into the
Nextcloud app; a core that depended on Nextcloud could never leave it.

### PHP versions

The core supports PHP 8.3 and newer, matching the app's `info.xml`
(`php min-version="8.3" max-version="8.5"`). CI runs 8.3 and 8.4; 8.5 is added
once the test runner supports it there.

## Consequences

- The standalone host can swap a component without touching the core.
- The core's tests run anywhere PHP runs, including CI containers and this
  sandbox, with no services.
- The Nextcloud host keeps using Nextcloud's own client, database and crypto —
  the ports exist precisely so it does not have to adopt ours.
