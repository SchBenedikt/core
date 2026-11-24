# Storage

Binary data goes through `Platform\Storage\BlobStore`. The core never writes a
file itself, never builds an absolute path and never assumes a bucket.

## Keys

A key is a relative path made of non-empty segments separated by `/`:

```
avatars/alice/9f2c.webp
posts/2026/11/11/0b1e.jpg
```

`BlobPath` normalises and validates before a driver sees a key:

- `\` becomes `/`;
- leading slashes and `.` segments are dropped;
- an empty segment, `..` or a NUL byte throws `StorageException`.

That is a security rule, not formatting: a key comes from an HTTP request and
must not be able to leave a root directory.

## Listing

`list($prefix)` returns sorted keys that are at or below the prefix, matched by
**segment**, not by string: `list('b')` returns `b/x`, not `bx`. A trailing
slash is a directory hint and is ignored, so `list('b/')` and `list('b')`
return the same keys.

## Implementations

| Class | Use |
| --- | --- |
| `LocalBlobStore` | a directory root; creates parents, writes atomically via a temp file and rename |
| `InMemoryBlobStore` | tests, the demo and tools; same rules, no disk |
| `S3BlobStore` | host-injected S3-compatible client; validates prefixed keys, bounds transfers and paginates listings |

All three pass the shared `BlobStoreTestCase`; the S3 adapter also has tests for
prefix isolation, streaming, transfer bounds, encryption arguments and paginated
listing. Hosts must test their concrete S3 client against the service they deploy.

## What the core does not do

- no quota accounting: the host owns its limits;
- no S3 network client or credentials: the host configures and injects an
  `S3CompatibleClient`; Core never reads endpoint, region or secrets itself;
- no automatic media sweeper. Image validation, thumbnails and the public
  media route live in `MediaService`, not in the store; see
  [media](media.md) for its layout and orphan cleanup.

A `BlobStore` still only answers bytes. It does not decide cache headers or
post visibility; the media route does that.

## Reading and writing

`put()` takes a key and the bytes, and returns nothing; `read()` returns the
bytes or throws. `exists()`/`size()` are cheap checks; `delete()` is idempotent
and is a no-op when there was nothing to delete.
