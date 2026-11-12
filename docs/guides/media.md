# Media

The core owns one media slice: an image uploaded by an account, stored as an
untouched original plus a bounded thumbnail, and referenced by a post. Remote
media fetching and avatar ingestion are separate features and are not implied
by this one.

## What is stored

`Attachment` keeps the metadata:

| Field | Meaning |
| --- | --- |
| `id` | random, URL-safe id; also the path segment of the media URL |
| `owner_id` | the uploading local account |
| `storage_key` | relative `BlobStore` key of the original |
| `thumbnail_key` | relative `BlobStore` key of the derived image |
| `mime_type` | sniffed from the bytes, never trusted from the upload header |
| `width`, `height` | decoded dimensions of the original |
| `byte_size` | size of the original |
| `alt_text` | optional description, up to 1000 characters |

Rows live in `social_attachment`; the link to a post lives in
`social_post_attachment` with a `position`, so attachment order is stable.
Bytes live only in the host's `BlobStore`. The local layout is:

```
media/{attachment-id}/original.{jpg|png|webp}
media/{attachment-id}/thumbnail.{jpg|png|webp}
```

The extension follows the sniffed MIME type. The original is never rewritten;
the thumbnail is a second object.

## Upload

```http
POST /api/v1/media
Authorization: Bearer …
Content-Type: multipart/form-data; boundary=…

--…
Content-Disposition: form-data; name="file"; filename="cat.png"
Content-Type: image/png

<bytes>
--…
Content-Disposition: form-data; name="alt_text"

A cat on a keyboard
--…--
```

The route needs an authenticated user and is rate-limited to 20 requests per
900 seconds. `file` is required. `alt_text` is optional; an empty or
whitespace-only value is stored as `null`.

Accepted input is JPEG, PNG or WebP. SVG is refused because it is scriptable,
and GIF is not accepted because an animated original would make a still
thumbnail a silent content change. The service checks the decoded image before
writing anything:

- bytes must be non-empty and at most `media.max_bytes` (hard ceiling 5 MiB);
- `getimagesizefromstring` must recognize a supported MIME type;
- dimensions must be positive, at most 10,000 per side and at most 40
  megapixels in total;
- the GD adapter must be able to produce the thumbnail.

A failed check is `422 validation_failed`, not a partial upload. The service
writes the original and thumbnail first, then inserts metadata. If a later step
fails, it deletes both blobs it just wrote, so a client never gets a URL whose
row is missing and a row never points at an incomplete pair.

The response contains the metadata plus `url` and `thumbnail_url`. Both are
built from `social/instance.url`; without that key they are relative paths.

## Fetch

```http
GET /api/v1/media/{id}
GET /api/v1/media/{id}?variant=thumbnail
```

The route is public and the id is random. A remote server fetching an image
referenced by a public or unlisted post must not need a local session. The
route serves the exact bytes with the stored `Content-Type`, a one-year
immutable cache header and `X-Content-Type-Options: nosniff`.

An absent row or blob is `404 not_found`. `variant` accepts only `thumbnail`
(or the default `original`); anything else is `400 invalid_media_variant`.

## Attaching to a post

`POST /api/v1/posts` accepts an optional `attachment_ids` array. The service
checks each id:

- at most four, no duplicates;
- the id must exist;
- its owner must be the author of the post.

A violation is `422 validation_failed` with `error.fields.attachment_ids`.
Post and attachment links are written in one transaction by the SQL adapter.
`ShowPostHandler`, `PublicTimelineHandler` and `HomeTimelineHandler` present the
same `attachments` array; a post without attachments keeps its old shape
exactly (the key is omitted). Remote timeline entries never carry a local
attachment relation.

## Configuration and operation

The host supplies a `BlobStore` to `Application::forHost()`:

```php
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;

$app = Application::forHost(
    // …
    blobStore: new LocalBlobStore('/var/lib/alohasocial/media'),
);
```

If no blob store is supplied, the media routes answer `503
media_not_configured`; there is no hidden fallback that would write into an
unintended directory. The local store creates parent directories with mode
`0770`, writes through a temporary file and an atomic rename, and uses file
mode `0660`. Make sure the worker and web process share the same root and
permissions. For a container, mount that path as a persistent volume.

Relevant settings:

| Key | Default | Effect |
| --- | --- | --- |
| `social/media.max_bytes` | 5 MiB | Lower ceiling for one upload; cannot exceed the domain hard limit |
| `social/media.thumbnail_max_dimension` | 640 | Longest side of the derived image, clamped to 1–2000 |

`ImageProcessor` is a port. `GdImageProcessor` is the complete local
implementation and requires `ext-gd`; a host that already runs ImageMagick or
libvips can provide another adapter without changing the service or schema.
There is no external image-library dependency in this repository.

### Orphan cleanup

Normal failed uploads clean themselves. `AttachmentRepository::count()` is
available for a cheap operational check. Two operator cases remain:

1. **Metadata row removed out of band.** The blobs are now unreferenced. Delete
   `media/{id}/original.*` and `media/{id}/thumbnail.*` (or remove the whole
   `media/{id}/` prefix) for that id.
2. **Upload interrupted after the blob write but before the row insert.** The
   service's `try`/`catch` handles PHP failures, but a hard process kill can
   leave a pair behind. Scan `BlobStore::list('media')`, group paths by the id
   segment, and compare those ids with `SELECT id FROM social_attachment`.
   Delete a group only when its id has no row and it is older than the upload
   grace period you choose (for example one hour), so an in-flight upload is
   not swept. Run this from a host cron with the same `BlobStore` instance.

There is no automatic sweeper in the core: deletion policy and retention
belong to the host, just like the queue worker. A future `Delete attachment`
route has to delete the row and both blobs in that order, with a retry-safe
cleanup of the blobs if the row delete succeeds.

## Open point

The local filesystem path is complete. An object-storage adapter (for example
S3) is a host/deployment choice and is not implemented or claimed here; any
such adapter has to pass the existing `BlobStoreTestCase`. Remote media
fetching for federated posts is also still open.
