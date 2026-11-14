# Security

Two things in this core are security boundaries: signature verification and
encryption at rest. Everything else is ordinary application logic.

## HTTP signatures

`Protocol\ActivityPub\HttpSignature`

- `sign()` produces `keyId="…",algorithm="rsa-sha256",headers="…",signature="…"`.
- `verify()` rebuilds the signing string from the **actual** request and
  refuses on any mismatch.
- The default signed set is `(request-target) host date`, plus `digest` when a
  body is present.
- A body-bearing request without a signed `digest` is refused: otherwise the
  signature would cover headers only and the payload could be swapped.
- `Date` outside the allowed window is refused (replay).
- The key id must be one the caller expects; verification does not fetch keys.

Rules for changing this code:

1. Read the tests before the implementation.
2. Keep every refusal case; add one for each new branch.
3. Never relax a check to make an interop test pass. Find out what the peer
   actually signs, log the signing string, and fix the peer or the
   documentation.
4. Do not log a private key, a sealed value or a complete request body that may
   contain one.

## Keys

`Protocol\ActivityPub\RsaKeyPair` wraps OpenSSL: generate, load from PEM, export
the public key, sign, verify. Key material never enters the core's
configuration; the host stores it and passes the pair in.

## Encryption at rest

`Security\OpenSslSecretBox` is AES-256-GCM with a random 12-byte nonce per
message and the key id as additional authenticated data. A sealed value is
`base64(nonce || tag || ciphertext)`.

- the nonce is random per seal — never reused;
- the tag is checked on open; a modified payload throws;
- the key is 32 raw bytes supplied by the host;
- the class does not derive a key from a password. If a host has a password, it
  derives a key with `hash_pbkdf2('sha256', …, 600000, 32, true)` **outside**
  the core and passes the result.

PHP's `sodium` extension is not assumed: the build here has OpenSSL only, and
AES-GCM is available in every supported installation.

## Outbound federation targets

`Service\Federation\FederationTargetGuard` is the shared destination check for
federation URLs. It accepts only http(s) without userinfo, rejects localhost
and literal private/reserved addresses, and, when a `HostResolver` is present,
requires every A/AAAA answer to be publicly routable. `FederationDeliveryHandler`
runs it before signing or opening a connection and uses the validated addresses
for connection pinning when the host supplied a `BoundedHttpClient`.
`HttpActorResolver` and `HttpActorInboxResolver` use the same guard, and
`Application` wraps any host-provided `ActorResolver` in `GuardedActorResolver`
so a custom resolver cannot bypass the check. The actor URL of a local account
under `social/instance.url` is the one deliberate exception: resolving it never
leaves the instance.

A signing-actor fetch is an inbound, peer-controlled request, so it is also
bounded and pinned: `GuardedActorResolver` hands the addresses it validated to
the inner resolver through `PinnedActorResolver`, and `HttpActorResolver` asks
`BoundedHttpClient::requestBounded()` for the document with a 10-second budget
and a 1 MiB body limit. The hostname is not resolved a second time, so a peer
cannot answer with a public address for the check and a private one for the
connection. There is no unbounded fallback: a resolver without its own guard,
and a guard without a resolver that yields no validated address, leave the
actor unknown instead of fetching it unchecked.

The production `Application::forHost()` defaults to `DnsHostResolver`; the
in-memory assembly without a resolver still blocks localhost and literal
private addresses, but cannot judge a hostname until a resolver is supplied —
and an `HttpActorResolver` on that assembly refuses to fetch a remote actor for
that reason.

## Transport

The core never decides whether a request is allowed to arrive: the host
terminates TLS, authenticates the caller and passes `userId`. A host that
skips authentication and passes a user id breaks every `requiresAuth` route at
once. The core can only refuse when `userId` is null.

Outbound requests are different: their destination comes from a peer. The
guard above is therefore part of the core's security boundary, not an optional
host concern. A host that supplies its own resolver or HTTP client still gets
the URL and public-address checks before any request.

## Error messages

An unexpected exception is logged and answered with a generic 500. Only the
debug kernel mode exposes the message, and that mode must never be enabled on a
public instance. Validation messages are safe: they describe the input, not the
system.
