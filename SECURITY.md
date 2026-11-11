# Security policy

## Reporting a vulnerability

Do **not** open a public issue. Use GitHub's private vulnerability reporting
("Security" → "Report a vulnerability") on this repository, or email the
maintainer at the address in the git history.

Include what you need to make the report actionable:

- affected version or commit;
- a minimal reproduction (a request, a payload, a test);
- impact — what an attacker gains;
- any suggested fix.

You will get an acknowledgement, an assessment, and a coordinated disclosure
date. Please give the project time to ship a fix before publishing.

## Supported versions

The project is pre-1.0. Security fixes land on `main` and, when a release
exists, in the latest minor line. Older tags are not maintained.

## Scope

In scope:

- HTTP signature verification (`HttpSignature`);
- encryption at rest (`OpenSslSecretBox`);
- authorization decisions in the services (post ownership, follower ownership);
- path handling in blob storage (`BlobPath`);
- anything that lets one account read or modify another account's data.

Out of scope:

- the host's TLS, proxy and rate limiting;
- a host that passes a `userId` without authenticating the caller — that is a
  host vulnerability, but please report it anyway so the documentation can warn;
- denial of service through huge request bodies, which the host must limit;
- missing features listed in the [roadmap](docs/roadmap.md).

## Handling secrets

- Never commit a private key, a token or a `.env` file.
- Do not paste a secret into an issue; redact it.
- A sealed value is safe to store, not safe to publish: it is encrypted with a
  key the host holds.

## What we already do

- Bodies are signed through a `Digest` header; a body without a signed digest is
  refused.
- Signature `Date` is checked against a clock window.
- Private posts answer 404 rather than 403, so existence does not leak.
- Access to a post and to a follower is scoped to the authenticated account.
- Error responses hide internals unless debug mode is explicitly enabled.
