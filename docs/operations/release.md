# Release artifacts

Status as of 2026-11-13: `0.7.0` is the current source version. The Composer
package is **not** on Packagist and `@alohasocial/ui` is **not** on npm. The
local artifacts are buildable and installable, but registry publication is
blocked on credentials that are not present in the repository or this
environment.

## Version and tag

The core version authority is `Version::CURRENT` in
[`src/Version.php`](../../src/Version.php); the UI authority is
[`ui/package.json`](../../ui/package.json). `composer.json` deliberately has no
`version` field, so a Composer archive does not silently become a second
version source. The distribution version comes from the git tag.

Tag `0.7.0` points at the released commit. A later packaging or code commit must
not reuse or move that tag. Publish from a tag whose version matches
`Version::CURRENT` and `ui/package.json`; the compatibility tests reject a
mismatched pair before the artifact is built, and CI rejects a tag that does
not match both versions. The tag check builds nothing and publishes nothing.

The packaging commit that introduced these rules is after the `0.7.0` tag. The
artifacts above were built from that working tree and are installable, but a
registry release from this state needs a new matching tag (and therefore the
maintainer's next-version decision); the existing `0.7.0` tag is not moved.

## One-command artifact check

`composer check:release` runs `tools/release-check.sh`, which builds both
artifacts, verifies their contents, installs the core archive without
development dependencies and checks the reported versions. It never publishes
and writes the archives to `var/release/` (or `RELEASE_OUT_DIR`). The release
workflow runs the same script after the core gate and the UI typecheck/tests; a
tag run also refuses a tag that differs from `Version::CURRENT` or
`ui/package.json`.

```bash
composer check:release
```

## Composer preflight

```bash
composer validate --strict
rm -rf /tmp/core-release
mkdir -p /tmp/core-release
composer archive --format=tar --dir=/tmp/core-release --file=alohasocial-core
tar -tf /tmp/core-release/alohasocial-core.tar
tar -tf /tmp/core-release/alohasocial-core.tar | grep -E '^(vendor|tests|ui|docs)/'
```

The last command must print nothing. The archive rules live in
[`.gitattributes`](../../.gitattributes) and the `archive.exclude` block in
[`composer.json`](../../composer.json). On 2026-11-13 the verified archive had
392 entries (1.5 MB) and contained only `src/`, `bin/`, `composer.json`,
`LICENSE`, `README.md`, `CHANGELOG.md`, `REUSE.toml` and `.gitattributes`.

A vendor-free install check uses the archive, not the working tree:

```bash
mkdir -p /tmp/core-install
tar -xf /tmp/core-release/alohasocial-core.tar -C /tmp/core-install
cd /tmp/core-install
composer install --no-dev --no-interaction --prefer-dist --no-scripts
php -r 'require "vendor/autoload.php"; echo AlohaSocial\Core\Version::CURRENT, "\n";'
```

This installs only the runtime dependency (`psr/log`) and prints `0.7.0`. A
tag-less archive has no Composer root version; set
`COMPOSER_ROOT_VERSION=$(php -r 'require "vendor/autoload.php"; echo AlohaSocial\Core\Version::CURRENT;')`
when testing it standalone. Installed as a normal dependency, the version comes
from Packagist's tag metadata.

## UI preflight

```bash
cd ui
npm ci
npm run typecheck
npm test
npm pack --dry-run
npm pack --pack-destination=/tmp/core-npm-package
tar -tf /tmp/core-npm-package/alohasocial-ui-0.7.0.tgz
```

The package tarball must contain `package/package.json`, `LICENSE`, `README.md`
and the built `dist/` files with `index.js`, `index.css` and `index.d.ts`; it
must not contain `src/`, `tests/`, `node_modules/` or TypeScript/Vite configs.
`prepack` rebuilds `dist/`, `files` limits the tarball and `publishConfig`
makes the scoped package public. On 2026-11-13 the verified tarball had 28
entries (about 30 kB) and a consumer install imported `UI_VERSION=0.7.0`,
`CORE_API_VERSION=v1` and `HOST_BRIDGE_CONTRACT_VERSION=1`.

## Publishing

Once credentials exist, the remaining steps are:

1. Ensure `Version::CURRENT`, `ui/package.json` and the tag are the same
   release line, with a clean `composer check` and UI run.
2. For Composer, submit the repository to Packagist once. Packagist reads the
   tag and Composer metadata; later tags trigger an update. There is no
   uploadable `.zip` in this repository.
3. For npm, publish from `ui/` with the public access configured in
   `publishConfig`; the tarball is built by `prepack`.
4. Verify with `composer show alohasocial/core --all` and
   `npm view @alohasocial/ui version`.

Do not commit `COMPOSER_AUTH`, an npm token, or a Packagist session. Registry
credentials belong in CI secrets, never in the repository.

## Current blocker

No registry credentials are available: `COMPOSER_AUTH` is unset, no Composer
`auth.json` is present, no npm token is configured, and `npm whoami` refuses
with `ENEEDAUTH`. `composer show alohasocial/core --all`, the Packagist API and
the npm registry all report that neither package exists. Publication therefore
cannot be performed from this environment; the verified artifacts above are
the furthest safe step.
