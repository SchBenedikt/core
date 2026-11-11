# Development

## Setup

Requirements: PHP 8.3+, Composer 2, and the extensions listed in the README.

```bash
composer install
composer check
```

`composer check` runs three gates in order:

| Script | What it does |
| --- | --- |
| `composer lint` | `php -l` on every file in `src`, `tests`, `bin` |
| `composer check:boundary` | fails if the core references a host (`OCP\`, `OCA\`, a `nextcloud/*` dependency) |
| `composer test` | PHPUnit |

Individual commands:

```bash
composer test
php vendor/bin/phpunit tests/Http
php vendor/bin/phpunit --filter HttpSignatureTest
php bin/core-demo
php bin/export-routes
```

### Without root

The repository does not need system PHP. A static PHP build plus
`composer.phar` is enough:

```bash
mkdir -p "$HOME/.local/php" "$HOME/.local/bin"
curl -fsSL -o "$HOME/.local/php/php.tar.gz" \
  https://dl.static-php.dev/static-php-cli/common/php-8.4.8-cli-linux-x86_64.tar.gz
tar xzf "$HOME/.local/php/php.tar.gz" -C "$HOME/.local/php"
ln -sf "$HOME/.local/php/php" "$HOME/.local/bin/php"
curl -fsSL -o "$HOME/.local/php/composer.phar" https://getcomposer.org/composer-stable.phar
printf '#!/bin/sh\nexec "%s/.local/php/php" "%s/.local/php/composer.phar" "$@"\n' "$HOME" "$HOME" \
  > "$HOME/.local/bin/composer"
chmod +x "$HOME/.local/bin/composer"
```

## Adding a feature

The order matters: the contract first, then the core, then the hosts.

1. **Ports** — does the host need to provide something new? Add it to
   `src/Platform/` with an in-memory implementation, and a contract test.
2. **Domain** — entity, value object, repository method. Add a test that names
   the rule, not the code.
3. **Service** — validation and orchestration. One method per use case; throw
   `ValidationException` for input and a domain exception for state.
4. **Route** — one entry in `src/Contract/Routes.php`.
5. **Handler** — `src/Api`, map input → service → response. No logic.
6. **Host adapters** — the standalone server and the Nextcloud app each add
   their adapter and, for a page, a thin controller and template.
7. **Documentation** — `docs/architecture.md` for behaviour, an ADR for a
   decision, and the route export if the HTTP surface changed.

### Definition of done

- [ ] `composer check` is green.
- [ ] New behaviour has a test that would fail without it.
- [ ] A new port implementation passes its contract test.
- [ ] `bin/export-routes` changed only if the HTTP surface did, and both hosts
      are checked against it.
- [ ] `docs/architecture.md` describes the feature if its flow is not obvious.
- [ ] No host name appears in `src/` outside a comment.

## Conventions

- **SPDX headers.** Every new file starts with
  `SPDX-FileCopyrightText: 2026 Aloha Social contributors` and
  `SPDX-License-Identifier: AGPL-3.0-or-later`. Files that cannot carry a
  header are listed in `REUSE.toml`.
- **Strict types.** `declare(strict_types=1)` in every PHP file.
- **Immutability.** Value objects are `final readonly`; state changes return a
  new instance.
- **No dead code.** No unused imports, no commented-out blocks, no helper that
  only one test used once.
- **Comments explain *why*.** A comment that restates the code is noise; the
  one that explains an invariant, a security decision or a workaround is not.
- **Commits** follow Conventional Commits with a scope: `feat(posts): …`,
  `fix(signature): …`, `docs(adr): …`.
- **Errors are values.** A failure a caller can act on is an exception with a
  stable `reason` or `errorCode`; a failure nobody can act on is a 500 and a
  log line.

## Touching a signature

`HttpSignature` is the security boundary of federation. If you change it:

1. Read `tests/Protocol/HttpSignatureTest.php` before the implementation.
2. Keep the refusal cases — changed body, changed header, stale date, unsigned
   digest, foreign key — and add one for every new branch.
3. Never weaken `verify()` to make an interop test pass. Find out what the peer
   signs first; the debug log prints the signing string.

## Testing style

- One assertion per behaviour, named after the behaviour:
  `testAChangedBodyIsDetected`, not `testVerify2`.
- Use `FrozenClock` and `SequenceIdGenerator`; never sleep and never depend on
  wall-clock time.
- Prefer the real object over a mock. Mocks are for ports with a remote side
  (`HttpClient`); everything else has an in-memory implementation.
- A bug fix starts with a failing test.
