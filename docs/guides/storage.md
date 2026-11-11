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

Both pass `BlobStoreTestCase`. A bucket-backed store is planned; it must pass
the same case and keep the same key rules.

## What the core does not do

- no image processing: resizing, thumbnails and metadata belong outside the
  core (the host runs ffmpeg or an image library);
- no public URLs: a store answers bytes, the host decides how they are served
  and with which cache headers;
- no quota accounting: the host owns its limits.

## Reading and writing

`put()` takes a key and the bytes, and returns nothing; `read()` returns the
bytes or throws. `exists()`/`size()` are cheap checks; `delete()` is idempotent
and returns false when there was nothing to delete.
