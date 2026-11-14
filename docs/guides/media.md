# Media

The core owns two media paths: an image, video or audio file uploaded by an
account, and media advertised by an incoming federated post. Both use the same
validation and storage pipeline. The original is stored untouched (metadata is
stripped from images before storage); a still image also gets a bounded
thumbnail. `Attachment` metadata points at both.

## What is stored

| Field | Meaning |
| --- | --- |
| `id` | random, URL-safe id; also the path segment of the media URL |
| `owner_id` | the local account (upload) or the remote actor (federated import) |
| `storage_key` | relative `BlobStore` key of the original |
| `thumbnail_key` | relative `BlobStore` key of the derived image; empty for video and audio |
| `mime_type` | sniffed from the bytes, never trusted from a declared header |
| `media_kind` | `image`, `video` or `audio`; derived from the sniffed MIME type |
| `width`, `height` | decoded dimensions of the original; `0` for audio and containers the probe cannot read |
| `duration_seconds` | whole seconds for MP4/M4A, MP3 and WAV; `0` when unknown |
| `animated` | whether a GIF contains more than one frame; exposed as `gifv` |
| `focus_x`, `focus_y` | optional normalized focal point in `-1..1` for a client crop |
| `byte_size` | size of the stored original |
| `alt_text` | optional description, up to 1000 characters |
| `source_url` | remote identity for an imported attachment; `NULL` for local uploads |

Rows live in `social_attachment`; the link to a local post lives in
`social_post_attachment` with a `position`. A remote post's imported media is
linked in `social_remote_post_attachment`, also with a position. `source_url`
has a unique index, so one remote URL produces at most one local attachment.
Bytes live only in the host's `BlobStore`. The local layout is:

```
media/{attachment-id}/original.{jpg|png|webp|gif|mp4|webm|mp3|m4a|ogg|wav}
media/{attachment-id}/thumbnail.{jpg|png|webp|gif}
```

The extension follows the stored MIME type. The original is never rewritten
after upload (except for the one-time image metadata strip); the thumbnail is a
second object. Video and audio have no thumbnail key, so `thumbnail_url` is
`null` and a request for `?variant=thumbnail` serves the original.

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

Accepted input is JPEG, PNG, WebP and GIF images, MP4 and WebM video and
MP3, MP4/M4A, Ogg and WAV audio. SVG, HTML and other scriptable documents are
refused. The service sniffs the type from the bytes before writing anything:

- image bytes must be at most `media.max_bytes` (hard ceiling 5 MiB);
- video bytes must be at most `media.max_video_bytes` (hard ceiling 20 MiB);
- audio bytes must be at most `media.max_audio_bytes` (hard ceiling 10 MiB);
- the multipart body may be at most `Attachment::MAX_REQUEST_BYTES` (25 MiB);
- `libmagic` must recognize one of the accepted types, and an explicit
  `Content-Type` must match it;
- image dimensions must be positive, at most 10,000 per side and at most 40
  megapixels in total;
- the GD adapter must be able to produce an image thumbnail. A host without a
  decoder rejects that image instead of storing it without a preview;
- when a host installs a `MediaTranscoder`, a video is transcoded and the
  result is sniffed again; a transcoder failure is `422`, never a fallback to
  unverified bytes. Without a transcoder the original video is stored as-is.

Image metadata (JPEG APP1/COM, PNG text/eXIf chunks, WebP EXIF/XMP) is removed
before the original is stored, so location data does not leak through the media
URL. The pixels are unchanged.

A failed check is `422 validation_failed`, not a partial upload. The service
writes the original and thumbnail first, then inserts metadata. If a later step
fails, it deletes both blobs it just wrote, so a client never gets a URL whose
row is missing and a row never points at an incomplete pair.

A post, scheduled post or remote import carries at most 10 attachments. The
`media.max_attachments` key may lower that limit; values above 10 are clamped.

The response contains the metadata plus `url` and `thumbnail_url`. Both are
built from `social/instance.url`; without that key they are relative paths.

## Fetch

```http
GET /api/v1/media/{id}
GET /api/v1/media/{id}?variant=thumbnail
```

The id is random, but unguessability is not authorization. `Service\MediaAccess`
binds each attachment to the post(s) that link it and answers with the post's
visibility and the requester's relationship to its author:

| Linking post | Who may fetch |
| --- | --- |
| `public`, `unlisted` | anybody, no session; `Cache-Control: public`, one year immutable |
| `followers` | the author and accepted local followers; `Cache-Control: private, no-store` |
| `direct` | the author and the local recipients; `Cache-Control: private, no-store` |
| deleted post | only its author |
| no post links it yet | anybody — the composer preview of a fresh upload |

Everybody else gets the same `404 not_found` as an unknown id, so the route
never confirms that an attachment exists, and the thumbnail variant follows the
exact same rule (no private bytes through `?variant=thumbnail`). Only the
attachment's own author may attach it (post creation checks ownership), so a
reused id cannot widen one author's audience through another author's post; if
the author links the same id from a public post, that post publishes it.

The route serves the exact bytes with the stored `Content-Type` and
`X-Content-Type-Options: nosniff`. An absent row or blob is `404 not_found`.
`variant` accepts only `thumbnail` (or the default `original`); anything else
is `400 invalid_media_variant`.

## Attaching to a local post

`POST /api/v1/posts` accepts an optional `attachment_ids` array. The service
checks each id:

- at most four, no duplicates;
- the id must exist;
- its owner must be the author of the post.

A violation is `422 validation_failed` with `error.fields.attachment_ids`.
Post and attachment links are written in one transaction by the SQL adapter.
`ShowPostHandler`, `PublicTimelineHandler` and `HomeTimelineHandler` present the
same `attachments` array; a post without attachments keeps its old shape
exactly (the key is omitted).

An edit replaces the whole attachment list when `attachment_ids` is sent: the
new ids must belong to the author, the count limit is the same as on creation,
and a rejected id leaves the post and its previous attachments untouched. An
`Update` delivered to a peer therefore carries the attachments of the current
version.

Remote edits do not re-import media: an inbound `Update` changes text and
content warning, while the attachments announced by the `Create` stay attached
with their existing blobs and links.

## Outgoing federation

`MediaService` is the note's attachment presenter, so the `Create(Note)` that
delivery enqueues and the item the outbox serves carry the same `attachment`
array: one `Image`, `Video` or `Audio` per linked attachment, in link order,
with `url` (`/api/v1/media/{id}`), `mediaType`, `width`/`height` when known,
an ActivityStreams `duration` for timed media and `name` when the author wrote
alt text. A post without media keeps no `attachment` key.

Only public and unlisted posts publish these links. A followers-only or direct
post sends no attachment entries: the media route has no signed-fetch identity
for a remote peer, so the link would either leak the bytes or dead-end. That is
the documented limitation of the current design — private media delivery to a
remote follower needs an authenticated fetch flow (HTTP-signature GET with the
peer's key and a follower check) and is tracked separately; the fail-closed
choice here keeps an unlisted or private blob URL out of every federated
document.

## Remote attachments from incoming posts

When a followed remote actor delivers a signed `Create` or `Announce` with an
embedded object, the post is stored first. The inbox then reads at most four
image entries from the object's `attachments` array. This happens after the
post insert and is best-effort: a failed image never rejects the post and never
removes the post's existing content.

An entry is imported when:

- its `url` is an absolute `http` or `https` URL without userinfo, at most 512
  characters; `url` may be a string, a link object with `href`, or a list of
  either;
- its ActivityStreams `type` is `Image`, or it is a `Document` with an image
  `mediaType`/`mimeType`;
- any declared `mediaType` or `mimeType` is one of `image/jpeg`, `image/png`
  or `image/webp`;
- the alternative text comes from `name` or `summary`, trimmed and shortened
  to `Attachment::MAX_ALT_TEXT_LENGTH`.

The declared type is not trusted on its own. The fetched bytes must still pass
the same `MediaService` validation as an upload, including an actual MIME
sniff, dimensions and pixel count. A declared type that disagrees with the
bytes is rejected. The thumbnail is generated by the same GD adapter.

The remote post is linked to successfully imported attachments through
`RemotePostRepository::linkAttachments()`. The home timeline resolves that
link and returns the same `attachments` shape as a local post: `alt_text`,
`url` and `thumbnail_url`, with URLs pointing at this instance. A post with no
successful imports keeps its previous shape and no `attachments` key.

### Historical catch-up through the outbox backfill

`RemoteOutboxBackfill` imports the same embedded-object attachments when it
stores a missed `Create` or `Announce`. It also re-runs the importer for an
activity that is already present, so a post stored while remote media was
disabled, before media ingestion existed, or after a failed attachment can be
repaired without a second post row. The unique `source_url` reuses an existing
local copy; an existing link is preserved when one advertised URL can no longer
be fetched. Backfill media uses the same fetcher, host resolver, validation,
limits and `media.remote_fetch` switch as the inbox. See
[remote outbox backfill](remote-backfill.md).

### Fetch guard rails

Remote bytes are hostile input. The fetch path enforces all of these before a
request is made and again for every redirect:

- only `http` and `https` URLs, no URL credentials;
- hostname resolution through the `HostResolver` port; every resolved IPv4 and
  IPv6 address must be globally routable. Loopback, private, link-local,
  carrier-grade NAT, multicast, documentation and unique-local ranges are
  blocked, including IPv4-mapped IPv6 forms;
- `CurlHttpClient` pins the connection to the validated address, so a second
  DNS answer cannot redirect the connection to a private address (DNS
  rebinding);
- redirects are followed manually, at most `media.remote_max_redirects` times;
  every `Location` target goes through the same scheme, host and address checks;
- only a 2xx response is accepted; missing, rejected and redirect responses
  produce a stable log reason;
- a hard response-body limit is enforced while reading, not only from a
  `Content-Length` header;
- a request timeout applies to each hop.

The fetch is synchronous in the inbox request or explicit backfill run. The
default limits are one connection per attachment, a 10-second timeout per hop
and at most 3 redirects. Four attachments can therefore add bounded latency to
one delivery or one backfilled activity.

### Configuration

All keys use the `social` app.

| Key | Default | Effect |
| --- | --- | --- |
| `media.remote_fetch` | `true` | Enables fetching and importing remote attachments. Set `false` or `0` to disable all remote media requests. |
| `media.remote_max_bytes` | 5 MiB | Per-response body limit; clamped to 1 byte–`Attachment::MAX_BYTES` |
| `media.remote_timeout_seconds` | 10 | Per-hop HTTP timeout; clamped to 1–30 seconds |
| `media.remote_max_redirects` | 3 | Redirects followed per fetch; clamped to 0–5 |
| `media.max_bytes` | 5 MiB | Local upload ceiling; also an upper bound for the remote body limit |
| `media.thumbnail_max_dimension` | 640 | Longest thumbnail side, clamped to 1–2000 |

With `media.remote_fetch` disabled, the parser/fetcher is not called, no
outbound request is made and no new attachment row is created. This applies to
both the live inbox and the outbox backfill. The remote post is still stored
and delivered without an `attachments` key. Existing links and local copies
from an earlier enabled period remain readable; disabling the feature is not a
deletion. Set the switch to exactly `false`, `0`, `off` or `no` (using the
normal `Config::getBool()` spellings) to disable it. A later backfill with the
switch enabled can repair a post that was stored during the disabled period.

A host that supplies only `HttpClient` rather than `BoundedHttpClient` gets the
same disabled behavior: there is no safe way to fetch an infinite body without
the bounded capability, so `Application` does not wire a remote-media service.
`CurlHttpClient` implements both interfaces and is the local production path.

### Failure reasons

Individual failures are logged at warning level with the `activity_id`,
`reason`, remote `url` and a human-readable `error`. The post remains
available. The stable reasons are:

| Reason | Meaning |
| --- | --- |
| `remote_media_disabled` | The switch was off when a fetch was attempted |
| `remote_media_invalid_metadata` | An entry or its type/declared type is malformed |
| `remote_media_invalid_url` | Missing, non-http(s), credential-bearing or oversized URL |
| `remote_media_unsupported_type` | A non-image type or unsupported declared MIME |
| `remote_media_dns_failed` | The host did not resolve |
| `remote_media_ssrf_blocked` | The URL or a redirect resolved to a blocked address |
| `remote_media_fetch_failed` | DNS/TLS/connection transport failure |
| `remote_media_timeout` | A request hop timed out |
| `remote_media_http_error` | A non-2xx response after redirect handling |
| `remote_media_too_many_redirects` | The redirect budget was exhausted |
| `remote_media_redirect_invalid` | A redirect had no usable/invalid `Location` |
| `remote_media_too_large` | The response exceeded the body limit |
| `remote_media_invalid_image` | The bytes failed the existing image validation, including a declared/sniffed type mismatch |
| `remote_media_import_failed` | Storage or database failure while importing one image |
| `remote_media_attachment_limit` | More than four usable attachments were advertised |
| `remote_media_link_failed` | The post could not be linked to the imported rows |
| `remote_media_cleanup_failed` | A failed link could not remove one newly created copy |

A failed individual attachment does not create a metadata row before its bytes
validate. If the link insert fails, newly created rows and their two blobs are
removed; an already existing local copy is kept for the next delivery to
reuse. The error is logged and the post stays available. A repeat or backfill
merges the links from an earlier import with the ones that still succeed, so a
transient failure does not orphan a previously displayed local attachment.

For a historical post, run the outbox backfill again after the cause is fixed
(disabled fetch, transient network error, corrected peer content). There is no
background media retry queue; each explicit backfill run retries the advertised
candidates in its bounded item window.

## Configuration and operation of local storage

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

`ImageProcessor` is a port. `GdImageProcessor` is the complete local
implementation and requires `ext-gd`; a host that already runs ImageMagick or
libvips can provide another adapter without changing the service or schema.
There is no external image-library dependency in this repository.

`MediaProbe` is the validation port. `NativeMediaProbe` uses `libmagic` for the
MIME sniff and reads MP4/M4A, MP3 and WAV metadata itself; it needs no FFmpeg.
A host may replace it with `ffprobe` to obtain dimensions and duration for
every container. `MediaTranscoder` is optional: when installed it receives a
video before storage and must return playable MP4/WebM bytes, which are sniffed
again; when absent, the uploaded video is stored unchanged. A failed
transcoder is a `422`, never a silent passthrough.

AVIF, HEIC/HEIF and MOV are not accepted by the core. A host has to convert
them to an accepted type before upload. Rendition/poster generation and any
background media queue are host-owned; the core stores a playable original and
only generates a still thumbnail for images.

### Orphan cleanup

Normal failed uploads and failed remote imports clean themselves. The core
`cleanup` job (version `0.6.0`) removes `social_attachment` metadata rows older
than the configured media window only when neither `social_post_attachment` nor
`social_remote_post_attachment` references them, then deletes both blob keys.
It never removes media that a local or remote post still uses, and a missing
`BlobStore` skips media rather than leaving a row without bytes. See
[retention cleanup](retention-cleanup.md).

Two operator cases remain outside that link-aware sweep:

1. **Metadata row removed out of band.** The blobs are now unreferenced. Delete
   `media/{id}/original.*` and `media/{id}/thumbnail.*` (or remove the whole
   `media/{id}/` prefix) for that id.
2. **Process killed after both blobs were written but before the row/link
   insert.** Scan `BlobStore::list('media')`, group paths by the id segment, and
   compare those ids with `SELECT id FROM social_attachment`. Delete a group
   only when its id has no row and it is older than an upload grace period you
   choose (for example one hour), so an in-flight import is not swept.

Remote rows that were imported and then lost their post link can be found with:

```sql
SELECT a.id, a.storage_key, a.thumbnail_key
FROM social_attachment a
LEFT JOIN social_remote_post_attachment rp ON rp.attachment_id = a.id
LEFT JOIN social_post_attachment p ON p.attachment_id = a.id
WHERE a.source_url IS NOT NULL AND rp.attachment_id IS NULL AND p.attachment_id IS NULL;
```

Delete a row and both blob keys together. A remote post soft-delete
intentionally keeps its shared local attachment rows: another post may reuse
the same source URL, and the unique URL is the idempotence key. The core cleanup
job checks both link tables, so a linked remote image is safe; it does not
remove remote-post links or remote-source rows itself. A host that deliberately
removes a remote post and its links can then let the normal media retention
sweep the unreferenced attachment.

## HTTP contract

Remote media adds no route. Its state appears through the existing post and
timeline responses, and its local bytes are served by the already documented
`GET /api/v1/media/{id}` route. Consequently remote media adds no route: the
manifest stays at 86 routes (and the OpenAPI export at 70 paths), and no
private fetch endpoint is exposed.

## Open point

The local filesystem path is complete. An object-storage adapter (for example
S3) is a host/deployment choice and is not implemented or claimed here; any
such adapter has to pass the existing `BlobStoreTestCase`. A host that wraps
its own HTTP client must also implement `BoundedHttpClient` (a body cap and
validated-address pinning) before remote media is enabled; otherwise the safe
fallback is to leave `media.remote_fetch` disabled.
