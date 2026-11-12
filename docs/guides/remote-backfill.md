# Remote outbox backfill

The inbox only stores what a peer delivers. If a delivery was lost, or a local
account followed an actor after it had already posted, `RemoteOutboxBackfill`
can read that actor's public outbox and store the missed `Create` and
`Announce` activities. When a `RemoteMediaService` is wired, it also catches up
the images advertised by those activities through the same guarded importer
the inbox uses.

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
`RemotePostFactory`; pass the optional `RemoteMediaService` and logger to add
historical media catch-up. The media importer only exists when the application
also has a blob store and a bounded HTTP client, exactly like the inbox
importer.

A backfill run uses the normal `media.remote_fetch` switch and limits. With the
switch off, posts are still stored but no image is requested; a later run with
the switch on goes through the importer again and can repair those posts.

## Rules

| Rule | Why |
| --- | --- |
| The actor must be followed by at least one local account | A backfill must not turn an arbitrary URL into an outbound fetch and a shared remote-post write |
| An optional `BlockedActorPolicy` can refuse the actor first | The same host policy that refuses inbound content also refuses a fetch that would restore it |
| Only the outbox's own origin is followed through `first`/`next` | A remote document must not redirect the fetch at another host |
| `maxPages` and `maxItems` are required bounds | A peer cannot create unbounded work by advertising a huge collection |
| Only `Create` and `Announce` are imported | These are the activities the inbox stores; other types are counted as skipped |
| The activity id is not claimed in the `ActivityLog` | A later signed delivery is deduplicated by the remote-post primary key, not rejected as a replay |
| Images only go through the existing `RemoteMediaService` | The same URL scheme, DNS/address checks, redirect rechecks, timeout, body limit, MIME/dimension validation and four-attachment cap apply as on the inbox path |
| A failed image never changes the post outcome | One bad historical attachment is logged and skipped; the post is still stored or counted as a duplicate, and other attachments remain available |
| A duplicate activity is re-run through the idempotent media importer | This repairs rows created before media ingestion or during a disabled/failed period; the unique `source_url` reuses an existing local copy instead of creating a second row or blob |
| Existing attachment links are preserved | If one advertised URL can no longer be fetched, a repeat does not drop an attachment that was already local and displayed |

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

The counts describe post rows only. Media is best-effort and may be absent from
a stored or duplicate post; its failures are logged with the stable
`remote_media_*` reasons in [media](media.md#failure-reasons) and do not turn
the activity into `skipped`.

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
`Delete` activities; those need separate fetching and retention rules. Media
is imported only from the embedded object of an imported `Create` or
`Announce`: a URI-only `Announce` has no attachment metadata to fetch, and
skipped entries do not trigger a media fetch. Paging, retries and scheduling
remain host concerns; run the service after a local follow or from a
maintenance command. There is no automatic retry of a failed historical image
beyond the next explicit backfill run.

The inbox and the backfill share `RemotePostFactory` and
`RemoteMediaService`, so the same object gets the same content, content
warning, author, timestamp fallback and media rules whichever path stored it.
The inbox still imports media for a live delivery after its own insert; the
backfill does the same after its insert and on a duplicate row.
