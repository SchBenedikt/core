# S3-compatible BlobStore

Core provides `S3BlobStore`, an optional `BlobStore` adapter for hosts that
already use an S3-compatible service. The host owns and injects the S3 client
through `S3CompatibleClient`; endpoint, region, credentials and network
timeouts never enter Core configuration. The local store remains the
standalone default, and hosts that do not opt into S3 do not change behavior.

```php
use AlohaSocial\Core\Platform\Storage\S3BlobStore;

$blobs = new S3BlobStore(
    client: $hostConfiguredS3Client, // implements S3CompatibleClient
    bucket: 'aloha-media',
    keyPrefix: 'production/media',
    serverSideEncryption: 'aws:kms',
    kmsKeyId: $hostKmsKeyId,
    maxObjectBytes: 268_435_456,
);

$app = Application::forHost(
    // ...host-owned services...
    blobStore: $blobs,
);
```

The adapter validates every relative path with the same `BlobPath` rules as
the local and in-memory stores. It prepends the optional key prefix, rejects
keys returned outside that prefix, enforces a configurable object-size limit,
and follows continuation tokens while listing. The S3 client returns one
bounded page at a time. The adapter exposes no ACL setting; implementations
must keep objects private and clean up multipart uploads when a write fails.
Upload encryption is optional `AES256` or `aws:kms`; a KMS key id is accepted
only with `aws:kms`.

`S3BlobStore` also implements `StreamingBlobStore`. Its stream methods avoid
buffering the full object in Core and pass a maximum response size to the
injected client. The host client must honor that bound while streaming. The
original `BlobStore::put()` and `read()` string methods remain available for
existing application code; they enforce the same maximum object size.

## Host client requirements

An implementation of `S3CompatibleClient` should use the host's existing,
maintained S3 library or transport. It must:

- apply the configured endpoint, region, credentials, TLS and timeout policy;
- stream upload and download bodies, enforce the supplied byte limit, and
  validate the declared upload length;
- send server-side encryption headers when requested and never set a public
  ACL;
- paginate object listing into `S3ObjectPage` values no larger than the
  requested page size;
- report a missing object as `null` for `headObject()` / `getObject()`;
- make deletion idempotent and remove incomplete multipart uploads after
  failed writes.

The Core test suite runs the shared `BlobStoreTestCase` against a fake client
and separately verifies prefix isolation, streaming, transfer bounds,
encryption arguments and paginated listing. A production host should also run
its client adapter against the S3-compatible service it deploys (for example,
MinIO); Core does not start a bucket or expose credentials to its tests.

## Moving existing blobs

Object keys are the existing BlobStore paths, optionally below the configured
key prefix. Database attachment rows do not need rewriting if each old
relative path is copied unchanged. A host migration should copy blobs while
preserving those paths, compare object counts and sizes (and checksums where
available), switch the injected `BlobStore`, then verify representative media
reads before removing local files. Keep the local copy for rollback until
every durable attachment reference has been checked; bucket cleanup must not
delete objects solely because an incomplete inventory did not list them.
