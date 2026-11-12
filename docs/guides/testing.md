# Testing

```sh
composer test
php vendor/bin/phpunit tests/Http
php vendor/bin/phpunit --filter HttpSignatureTest
```

`phpunit.xml` fails on warnings, risky tests and unexpected output, so a test
that prints or skips is a failure.

## Layout

| Directory | What lives there |
| --- | --- |
| `tests/Platform` | clock, config, PDO connection, blob stores |
| `tests/Domain` | post, follower and followed-actor entities, all repository contracts |
| `tests/Service` | post, timeline and follower rules |
| `tests/Protocol` | ActivityStreams documents, RSA keys, signatures |
| `tests/Security` | the secret box |
| `tests/Jobs` | queue retry/dead-letter and the worker |
| `tests/Http` | request, response, router, kernel |
| `tests/Contract` | the route manifest |
| `tests/ApplicationTest.php` | end to end through the in-memory assembly |

## Contract tests

An abstract test case is the definition of a port. Two implementations, one
case:

```php
final class InMemoryPostRepositoryTest extends PostRepositoryTestCase { … }
final class PdoPostRepositoryTest extends PostRepositoryTestCase { … }
```

If a behaviour is not in the case, the two implementations may drift. Add the
expectation to the case, not to one subclass.

## Fakes and determinism

- `FrozenClock` — time moves only when the test says so; never `sleep()`.
- `RandomIdGenerator` — ids are random; assert shape, not value.
- `FakeHttpClient` — records requests and answers from a queue (default 202).
- `FixedInboxResolver` — pretends the follow graph has entries.
- `RecordingJobHandler` / `FailingJobHandler` — worker tests without work.
- `SequenceIdGenerator` — deterministic ids when a test needs them.

Prefer a real object over a mock. Only ports with a remote side (`HttpClient`)
need a fake; everything else has an in-memory implementation.

## What to test

- Every new branch and every refusal: validation, 401/403/404, expired
  signature, changed body, replayed activity, actor mismatch.
- Every new port implementation against the existing contract case.
- A new endpoint end to end: request in, response out, side effect visible.

A bug fix starts with a failing test. If the test cannot fail, it is not a test.

## Running on several PHP versions

The suite runs on 8.3, 8.4 and 8.5; CI covers all three. Locally, a static PHP
build is enough:

```sh
"$HOME/.local/php-8.3.9/php" vendor/bin/phpunit
```

If OpenSSL cannot find a config in a static build, point it at the system one:

```sh
OPENSSL_CONF=/etc/ssl/openssl.cnf "$HOME/.local/php-8.3.9/php" vendor/bin/phpunit
```
