# Configuration and limits

The core reads configuration through `Platform\Config\Config`, a two-level
store (`app`, `key`). A host maps its own source onto it — `config.php`, `.env`,
the database — the core does not care.

## Keys

| App | Key | Required | Meaning |
| --- | --- | --- | --- |
| `social` | `instance.url` | for federation | Absolute http(s) base URL, no trailing slash required |
| `social` | `instance.name` | no | Display name; defaults to the product name |

`instance.url` is read when an activity is built. A post with an audience on an
instance without it fails with `503 instance_not_configured` instead of sending
documents with a broken id.

`Config` is not a secret store. Private keys are passed as objects, and when a
host persists them it seals them with `Security\SecretBox`.

## Limits

| Constant | Value | Meaning |
| --- | --- | --- |
| `PostService::MAX_CONTENT_LENGTH` | 5000 | Characters after trimming |
| `PostService::MAX_CONTENT_WARNING_LENGTH` | 200 | Characters after trimming |
| `TimelineService::DEFAULT_LIMIT` | 20 | Default page size |
| `TimelineService::MAX_LIMIT` | 40 | Page size ceiling |
| `FollowerService::DEFAULT_LIMIT` | 20 | Default follower page |
| `FollowerService::MAX_LIMIT` | 100 | Follower page ceiling |
| `FollowerService::MAX_ACTOR_LENGTH` | 255 | Indexed actor URL length |
| `FollowerService::MAX_INBOX_LENGTH` | 512 | Inbox URL length |
| `FollowingService::MAX_ACTOR_LENGTH` | 255 | Indexed actor URL length of an outgoing follow |
| `FollowingService::MAX_INBOX_LENGTH` | 512 | Inbox URL length of an outgoing follow |
| `InboxService::MAX_SKEW_SECONDS` | 300 | Allowed clock skew of a signed inbox request |
| `InMemoryActivityLog::RETENTION_SECONDS` / `PdoActivityLog::RETENTION_SECONDS` | 86400 | How long an accepted activity id blocks a replay |
| `InMemoryJobQueue::MAX_ATTEMPTS` / `PdoJobQueue::MAX_ATTEMPTS` | 5 | Attempts before a dead letter |
| `InMemoryJobQueue::BASE_BACKOFF_SECONDS` / `PdoJobQueue::BASE_BACKOFF_SECONDS` | 30 | First retry delay |
| `InMemoryJobQueue::MAX_BACKOFF_SECONDS` / `PdoJobQueue::MAX_BACKOFF_SECONDS` | 3600 | Backoff ceiling |
| `PdoJobQueue::RESERVATION_SECONDS` | 300 | A reservation older than this may be claimed by another worker |
| `RandomIdGenerator::BYTES` | 12 | 96 bits of randomness |
| `RouteManifest::VERSION` | 1 | Export document version |
| `ApiVersion::BASE_PATH` | `/api/v1` | API prefix |

The queue and activity-log limits are shared by the in-memory and PDO
implementations; `PdoJobQueue` aliases the retry constants from
`InMemoryJobQueue` so a successful retry and a dead letter happen at the same
attempt in both. `PdoJobQueue::RESERVATION_SECONDS` only affects crash
recovery; it does not make a normal retry faster.

Changing a limit is a product decision, not a refactor: a client may render
"5000 characters" and a test asserts it. Change the constant, the test and the
docs together.

## Environment of a host

The core reads no environment variable itself. The standalone host is expected
to map at least:

```
ALOHASOCIAL_INSTANCE_URL=https://social.example
ALOHASOCIAL_INSTANCE_NAME=Aloha Social
```

Those names are a proposal for the not-yet-existing server repository, not a
contract of this package.
