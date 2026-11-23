# Release artifacts

The supported release line is Core `0.8.x`, UI `0.8.x`, HostBridge contract
`1`. `Version::CURRENT`, `ui/package.json`, `ui/package-lock.json` and
`ui/src/version.ts` are checked together. The prior `0.7.0` tag stays immutable;
the next release uses the signed `v0.8.0` tag.

## What a release contains

After a signed `vX.Y.Z` tag passes the compatibility and package checks, the
release workflow creates a GitHub Release with:

- `alohasocial-core.tar`: the Composer source archive, excluding development
  files and vendor dependencies;
- `alohasocial-ui-X.Y.Z.tgz`: the npm package with built JavaScript, CSS,
  declarations, license and README;
- `SHA256SUMS`: hashes of both archives.

The workflow also creates GitHub artifact attestations for the archives. The
signed tag identifies the source commit; the checksums and attestations bind the
published packages to the workflow run that built them. Packagist and npm
mirrors remain optional and can be added when registry credentials are
available.

## Verify before release

Every pull request runs the same package check as a release:

```bash
composer check:release
```

It validates the Composer archive contents, installs that archive without dev
dependencies, builds and packs the UI, checks the package contents, installs
the packed UI in a clean consumer directory and imports the public API. The
release workflow also runs the full Core and UI suites. A tag is rejected
unless it is annotated, GitHub verifies its signature, and the tag version
matches all package version authorities.

## Install a released version

Composer can resolve the signed Git tag directly from GitHub without Packagist:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/SchBenedikt/core" }
  ],
  "require": {
    "alohasocial/core": "^0.8"
  }
}
```

Install the UI tarball from the matching GitHub Release:

```bash
npm install https://github.com/SchBenedikt/core/releases/download/v0.8.0/alohasocial-ui-0.8.0.tgz
```

The UI package declares Vue `^3.5.0` as a peer dependency. Hosts must pin Core
and UI to the same `0.8.x` compatibility line. From `1.0`, the major version
becomes the compatibility boundary; the HostBridge contract remains separately
versioned.

Only the current `0.x` minor line receives fixes; earlier minor lines are
unsupported. A host upgrade should update Core and UI together, run its full
compatibility and federation smoke tests, and keep its previous lockfiles and
database backup for rollback. `0.x` minor releases may include breaking changes.

Example Core update:

```bash
composer require alohasocial/core:^0.8 --with-all-dependencies
composer check
```

Example UI update:

```bash
npm install https://github.com/SchBenedikt/core/releases/download/v0.8.0/alohasocial-ui-0.8.0.tgz
npm run typecheck
npm test
```

Verify checksums and provenance with the GitHub CLI:

```bash
sha256sum --check SHA256SUMS
gh attestation verify alohasocial-core.tar --repo SchBenedikt/core
gh attestation verify alohasocial-ui-0.8.0.tgz --repo SchBenedikt/core
```

## Cut a release

1. Update the changelog and keep the Core, UI and HostBridge compatibility
   checks green.
2. Merge the release preparation pull request. Do not move or reuse an old
   version tag.
3. Create and push a signed annotated tag for the matching version:

   ```bash
   git tag -s v0.8.0 -m "Release v0.8.0"
   git push origin v0.8.0
   ```

4. Wait for the tag workflow to verify the signature, run all gates and create
   the GitHub Release. If a check fails, fix the source and use a new version;
   do not replace an existing published tag or artifact.
5. Pin the release in each host and keep the prior package/lockfile available
   for rollback.

Do not commit registry tokens, Composer auth, or signing secrets. The release
workflow only creates a GitHub Release from a signed tag; registry publishing
is a separate optional workflow.
