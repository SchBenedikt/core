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

## Transport

The core never decides whether a request is allowed to arrive: the host
terminates TLS, authenticates the caller and passes `userId`. A host that
skips authentication and passes a user id breaks every `requiresAuth` route at
once. The core can only refuse when `userId` is null.

## Error messages

An unexpected exception is logged and answered with a generic 500. Only the
debug kernel mode exposes the message, and that mode must never be enabled on a
public instance. Validation messages are safe: they describe the input, not the
system.
