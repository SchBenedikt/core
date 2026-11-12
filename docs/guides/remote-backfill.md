# Remote outbox backfill

The inbox only stores what a peer delivers. If a delivery was lost, or a local
account followed an actor after it had already posted, `RemoteOutboxBackfill`
can read that actor's public outbox and store the missed `Create` and
`Announce` activities.

It is an explicit host operation, not a route and not part of `POST /inbox`.

## Use it

`Application::forHost()` exposes a service when it has an HTTP client:

```php
$report = $app->backfill->backfill(
    actorId: 'https://remote.example/users/bob',
    outboxUrl: 'https://remote.example/users/bob/outbox',
    maxPages: 10,
    maxItems: 200,
);

$report->toArray();
// ['pages' => 1, 'items' => 20, 'stored' => 3, 'duplicates' => 17, 'skipped' => 0]
```

`Application::inMemory()` returns `null` for `$app->backfill` when no HTTP
client was passed. A host may also construct the service directly with an
`HttpClient`, the `RemotePostRepository`, the `FollowingRepository` and a
`RemotePostFactory`.

## Rules

| Rule | Why |
| --- | --- |
| The actor must be followed by at least one local account | A backfill must not turn an arbitrary URL into an outbound fetch and a shared remote-post write |
| An optional `BlockedActorPolicy` can refuse the actor first | The same host policy that refuses inbound content also refuses a fetch that would restore it |
| Only the outbox's own origin is followed through `first`/`next` | A remote document must not redirect the fetch at another host |
| `maxPages` and `maxItems` are required bounds | A peer cannot create unbounded work by advertising a huge collection |
| Only `Create` and `Announce` are imported | These are the activities the inbox stores; other types are counted as skipped |
| The activity id is not claimed in the `ActivityLog` | A later signed delivery is deduplicated by the remote-post primary key, not rejected as a replay |

The outbox root may be an `OrderedCollection` with a `first` URL or embedded
page. Each page is read from `orderedItems`; a `next` URL is followed until the
page or item limit is reached. A missing `next` ends the run normally.

## What the report means

- `pages` — how many collection pages were fetched.
- `items` — entries seen, up to `maxItems`.
- `stored` — new remote-post rows.
- `duplicates` — an activity id that already existed; no second row was made.
- `skipped` — unsupported type, foreign actor, or an activity that failed the
  same validation the inbox uses.

Malformed individual entries do not abort the run. A page that cannot be
fetched, an invalid document or a cross-origin pagination link raises
`RemoteBackfillException` with a stable reason:

| Reason | Meaning |
| --- | --- |
| `blocked_actor` | The host's `BlockedActorPolicy` refuses the actor; nothing was fetched |
| `not_followed` | No local account follows the actor; nothing was fetched |
| `invalid_outbox` | The outbox URL is not an absolute HTTP(S) URL |
| `fetch_failed` | A transport error or a non-2xx answer |
| `invalid_document` | The page is not a JSON object or has malformed paging fields |
| `pagination_scope` | `first`/`next` pointed at another origin |

## What it does not do

This is not a complete mirror. It does not fetch replies, threads, context or
media, and it does not apply `Delete` activities; those need separate fetching
and retention rules. Media ingestion is implemented on the live inbox path
(see [media](media.md#remote-attachments-from-incoming-posts)); a host that
backfills historic posts needs its own media catch-up policy. The backfill also
does not schedule itself — the host decides when to run it, for example after a
local follow or from a maintenance command.

The inbox and the backfill share `RemotePostFactory`, so the same object gets
the same content, content warning, author and timestamp fallback whichever path
stored it.
