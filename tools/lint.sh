#!/usr/bin/env bash
# Syntax check for every PHP file of the package.
set -euo pipefail

cd "$(dirname "$0")/.."

targets=()
for directory in src tests bin; do
	if [ -d "$directory" ]; then
		targets+=("$directory")
	fi
done

if [ "${#targets[@]}" -eq 0 ]; then
	echo "nothing to lint" >&2
	exit 1
fi

failed=0
while IFS= read -r -d '' file; do
	if ! php -l "$file" >/dev/null; then
		echo "syntax error: $file" >&2
		php -l "$file" >&2 || true
		failed=1
	fi
done < <(find "${targets[@]}" -type f \( -name '*.php' -o -path 'bin/*' \) -print0)

if [ "$failed" -ne 0 ]; then
	echo "lint failed" >&2
	exit 1
fi

echo "lint ok"
