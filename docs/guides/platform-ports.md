# Platform ports

A port is what the core needs from the world. The small set lives in
`src/Platform/`, and both hosts implement it. Adding another is a breaking
change for both hosts, so the list stays short on purpose. `BoundedHttpClient`
extends the HTTP port rather than adding a second outbound stack, and
`HostResolver` exists so the remote-media guard can validate DNS before a
request.

| Port | Methods | In-memory | Production |
| --- | --- | --- | --- |
| `Clock` | `now()` | `FrozenClock` | `SystemClock` |
| `Config` | `get`/`set`/`delete`/`has`/`all`, typed getters | `ArrayConfig` | host configuration |
| `Storage\BlobStore` | `put`/`read`/`exists`/`delete`/`size`/`list` | `InMemoryBlobStore` | `LocalBlobStore`, S3 later |
| `Db\Connection` | `execute`/`fetchAll`/`fetchOne`/`fetchValue`/`transaction`/`driver` | — | `PdoConnection` |
| `Db\TransactionRunner` | `transaction()` | — | `PdoConnection`; a host database adapter |
| `Http\HttpClient` | `request()` | `FakeHttpClient` (tests) | `CurlHttpClient` |
| `Http\BoundedHttpClient` | `request()` + bounded `requestBounded()` | `FakeHttpClient` (tests) | `CurlHttpClient` |
| `Network\HostResolver` | `resolve()` | `DnsHostResolver`, test map | `DnsHostResolver` |
| `Image\ImageProcessor` | `thumbnail()` | test adapter | `GdImageProcessor` |

## What each one promises

### Clock

`now()` returns an immutable `DateTimeImmutable` in UTC. Nothing in the core
calls `time()` or `date()` for persisted or federated values, which is what
makes the tests deterministic: `FrozenClock` moves only when a test moves it.

### Config

A two-level store (`app`, `key`) with typed getters that take a default. The core reads `social/instance.url`, `social/instance.name`, the optional
public `instance.*` metadata (description, version, contact email/account,
registration flags, languages and thumbnail) and the optional media limits (`media.max_bytes`, `media.thumbnail_max_dimension`,
`media.remote_fetch`, `media.remote_max_bytes`, `media.remote_timeout_seconds`
and `media.remote_max_redirects`) and `atproto.publish`; a host may store them
in `config.php`, an `.env` file or the database. `Config` is not a secret store — private keys
belong in the host's secret handling, sealed with `Security\SecretBox` when
persisted.

### BlobStore

Keys are relative paths, never absolute, and `BlobPath` rejects empty segments,
`..` and backslashes before a driver sees them. A store may be backed by a
directory, an object bucket or memory, but `list($prefix)` must return sorted
paths that match by path segment, not by string prefix.

### ImageProcessor

The `thumbnail()` method returns encoded bytes and their dimensions; it never
mutates the input string. `GdImageProcessor` is the complete local adapter and
needs `ext-gd`; a host with ImageMagick or libvips can implement the same port.
The media service validates type, dimensions and pixel count before calling
it, so an adapter does not have to repeat those policy checks.

### Connection

A deliberately small SQL port: statements and bound parameters, no query
builder. `execute()` returns the affected row count, which is how a repository
tells "deleted" from "was not there". `transaction()` joins nested calls and
rolls the whole chain back if any level throws.

`TransactionRunner` is the service-facing subset: a use case that writes
through several repositories needs a boundary, not SQL access. `PdoConnection`
implements both interfaces; `Application::forHost()` detects it and passes it to
`PostService`. A host with another database adapter should implement
`TransactionRunner` too (or pass a dedicated `transactions:` adapter), because
the in-memory fallback runs the writes directly and cannot roll back a
half-written post. The port has no implicit retry: an exception is rolled back
and propagated.

### HttpClient

One request method with method, URL, headers, body and timeout, returning a
status and body or throwing `HttpClientException` on a transport failure. A
non-2xx answer is **not** an exception — the delivery handler decides whether a
status is retryable.

`BoundedHttpClient` adds a response-body cap and an optional list of previously
validated addresses that a client must pin for the connection. Remote media
uses this capability; actor-key resolution and delivery only need the base
interface. A host that cannot provide the bounded guarantee simply leaves the
remote-media importer unwired.

### HostResolver

Resolves every address for a hostname before a remote media request. The
default `DnsHostResolver` asks for A and AAAA records and returns all answers;
the media guard rejects the fetch if any answer is private, loopback or
otherwise non-public. Tests use a small map, and a host may supply its own DNS
policy. Returning an empty list means the name did not resolve.

## Domain-level host ports

AT Protocol adds four domain-level ports rather than new `Platform` methods:
`Domain\Atproto\AtprotoAccountResolver` returns the host's linked account for a
local user (or `null`), `Domain\Atproto\AtprotoPublisher` writes one record to
a PDS, `Domain\Atproto\AtprotoDeleter` removes a record this core published, and
`Domain\Atproto\AtprotoSessionStore` persists the host-owned OAuth session.
Two protocol-level resolvers sit beside them: `Protocol\Atproto\AtprotoIdentityResolver`
turns a mention handle into a DID and `Protocol\Atproto\AtprotoRecordResolver`
reads a record with its CID for a reply reference. Both are optional, reuse
`HttpClient`, and are wired automatically by the standard assemblies.
`XrpcAtprotoPublisher` is the production record adapter over the existing
`HttpClient`; `XrpcAtprotoSessionRefresher` and `Es256DpopProofProvider` are the
OAuth refresh collaborators. `AtprotoOAuthMetadataResolver` and
`XrpcAtprotoOAuthClient` perform discovery, PAR, code exchange and revocation
over the same `HttpClient`; `DpopKeyThumbprint` and `DpopResourceProofProvider` are optional capabilities
on the existing proof port for `dpop_jkt` and for authenticated PDS requests. Tests use fakes for the network and a
fake proof provider where cryptography is not the subject. The account-linking
UI/callback, flow store, PDS selection and the real credential store stay in
the host; `InMemoryAtprotoSessionStore` is only the test/tool adapter. See
[AT Protocol publishing](atproto.md).

## Optional count capabilities

Instance statistics do not add a required port. Three narrow capability
interfaces extend the existing stores and are checked with `instanceof` by
`InstanceInfo`: `LocalActorCount::localActorCount()`, `PostCount::countLive()`
and `RemoteDomainCount::remoteDomainCount()`. The account directory and the
local/remote post repositories already used by the host may implement them; a
store that does not simply leaves the corresponding field at zero. This keeps
`LocalActorDirectory`, `PostRepository` and `RemotePostRepository` unchanged for
existing adapters and avoids a second statistics table or a scan hidden in the
endpoint. The built-in in-memory directory, both local post repositories and
both remote-post repositories implement the capabilities; the PDO remote-post
adapter counts distinct live actor hosts in one query.

## Contract tests

Several ports have a shared test case that every implementation must pass:

- `tests/Platform/Storage/BlobStoreTestCase.php`
- `tests/Domain/PostRepositoryTestCase.php` (including the optional `PostCount` capability)
- `tests/Domain/RemotePostRepositoryTestCase.php` (including the optional `RemoteDomainCount` capability)
- `tests/Domain/AttachmentRepositoryTestCase.php`
- `tests/Domain/FollowerRepositoryTestCase.php`
- `tests/Domain/FollowingRepositoryTestCase.php`
- `tests/Domain/AtprotoPublicationRepositoryTestCase.php`
- `tests/Domain/AtprotoSessionStoreTestCase.php`

The `Platform\Db\TransactionRunner` contract is exercised through
`tests/Service/PostTransactionTest.php` and the SQL host test, because its
promise is a rollback across repositories rather than a return value. A new
runner should pass both a commit and a forced-failure case.

A new adapter is done when it passes the existing case, not when its own tests
pass. That is the mechanism that keeps `InMemoryPostRepository` and
`PdoPostRepository` behaviourally identical.

The application-level `Jobs\JobQueue` and `ActivityLog` ports have their own
shared cases in `tests/Jobs/JobQueueTestCase.php` and
`tests/Domain/ActivityLogTestCase.php`; `PdoJobQueue` and `PdoActivityLog` run
those cases against SQLite as well. The remote-post and combined home-timeline
repositories have their own contracts in
`tests/Domain/RemotePostRepositoryTestCase.php` and
`tests/Domain/HomeTimelineRepositoryTestCase.php`, each run against the
in-memory and PDO adapters.

```php
final class S3BlobStoreTest extends BlobStoreTestCase
{
    protected function store(): BlobStore
    {
        return new S3BlobStore(/* ... */);
    }
}
```

## Rules for a new port

1. At least two implementations exist or are planned — otherwise it is not a
   port, it is a class.
2. The interface speaks in domain terms (`inboxesFor`, not `queryFollowers`).
3. No host type appears in a signature: no `IUser`, no `Request`, no `PDO`.
4. Throw a port-specific exception, not a driver exception.
5. Add the contract test first; both hosts will implement against it.
