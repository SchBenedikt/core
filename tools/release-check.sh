#!/usr/bin/env bash
# Build and verify the two release artifacts without publishing them.
set -euo pipefail

cd "$(dirname "$0")/.."

out="${RELEASE_OUT_DIR:-$PWD/var/release}"
tmp="$(mktemp -d)"
cleanup() {
	rm -rf "$tmp"
}
trap cleanup EXIT

rm -rf "$out"
mkdir -p "$out"

fail() {
	echo "release check failed: $*" >&2
	exit 1
}

require_file() {
	local archive="$1"
	local entry="$2"
	tar -tf "$archive" | grep -Fx "$entry" >/dev/null || fail "missing $entry in $archive"
}

composer_cmd="${COMPOSER_BIN:-composer}"
for command in php "${composer_cmd%% *}" npm node tar; do
	command -v "$command" >/dev/null 2>&1 || fail "$command is required"
done

core_version="$(php -r 'require "vendor/autoload.php"; echo AlohaSocial\Core\Version::CURRENT;')"
ui_version="$(node -e 'const p = require("./ui/package.json"); process.stdout.write(p.version);')"
[ "$core_version" = "$ui_version" ] || fail "core $core_version and UI $ui_version versions differ"

$composer_cmd validate --strict >/dev/null
$composer_cmd archive --format=tar --dir="$out" --file=alohasocial-core >/dev/null
core_archive="$out/alohasocial-core.tar"
[ -f "$core_archive" ] || fail "Composer archive was not created"

# The dist archive is runtime source, not a development checkout. Keep this
# explicit so a new ignore rule cannot silently ship tests or host adapters.
if tar -tf "$core_archive" | grep -E '^(vendor|tests|ui|docs|tools|\.github|\.git|phpunit\.xml|composer\.lock)(/|$)' >/dev/null; then
	fail "Composer archive contains development-only paths"
fi

for entry in composer.json LICENSE README.md CHANGELOG.md REUSE.toml .gitattributes; do
	require_file "$core_archive" "$entry"
done
require_file "$core_archive" 'src/Version.php'
require_file "$core_archive" 'bin/core-demo'

mkdir -p "$tmp/core-install"
tar -xf "$core_archive" -C "$tmp/core-install"
$composer_cmd install --working-dir="$tmp/core-install" --no-dev --no-interaction --prefer-dist --no-scripts >/dev/null
installed_version="$(php -r 'require $argv[1] . "/vendor/autoload.php"; echo AlohaSocial\Core\Version::CURRENT;' "$tmp/core-install")"
[ "$installed_version" = "$core_version" ] || fail "installed core reports $installed_version, expected $core_version"

(
	cd ui
	npm run build >/dev/null
	npm pack --pack-destination "$out" >/dev/null
)
ui_archive="$(find "$out" -maxdepth 1 -name 'alohasocial-ui-*.tgz' -print -quit)"
[ -n "$ui_archive" ] || fail "UI package was not created"

for entry in package/package.json package/LICENSE package/README.md package/dist/index.js package/dist/index.css package/dist/index.d.ts; do
	require_file "$ui_archive" "$entry"
done
if tar -tzf "$ui_archive" | grep -E '(^|/)node_modules/|(^|/)src/|(^|/)tests/|\.vue$|tsconfig|vite\.config' >/dev/null; then
	fail "UI package contains development-only paths"
fi
packed_version="$(tar -xOf "$ui_archive" package/package.json | node -e 'let s=""; process.stdin.on("data", d => s += d); process.stdin.on("end", () => process.stdout.write(JSON.parse(s).version));')"
[ "$packed_version" = "$ui_version" ] || fail "packed UI reports $packed_version, expected $ui_version"

echo "release check ok: core $core_version, UI $ui_version"
echo "artifacts: $core_archive"
echo "artifacts: $ui_archive"
