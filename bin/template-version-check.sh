#!/usr/bin/env bash
#
# Template versioning guard (local-CI stage 1.9). Identical in Free and Pro.
#
# Every template a theme may override carries `@version` in its header, and
# that version changes when - and only when - the template changes. Site Health
# compares a theme's copy against it, so an unbumped change hides a stale copy
# from the site owner (Basecamp 10344471983).
#
# Checks, for every overridable template (templates/**/*.php except
# templates/admin/, plus any --include path):
#   1. it has an `@version` line;
#   2. if it changed since the last release tag, its `@version` changed too.
#
# Usage: bash bin/template-version-check.sh [--include templates/admin/x.php ...]
#
set -euo pipefail

cd "$(dirname "$0")/.."

includes=()
while [ $# -gt 0 ]; do
	case "$1" in
		--include) includes+=("$2"); shift 2 ;;
		*) echo "Unknown argument: $1" >&2; exit 2 ;;
	esac
done

version_line() {
	grep -m1 -E '^[[:space:]]*\*[[:space:]]*@version[[:space:]]' || true
}

files=$(
	{
		find templates -name '*.php' -not -path 'templates/admin/*'
		for f in "${includes[@]+"${includes[@]}"}"; do echo "$f"; done
	} | sort -u
)

base=$(git describe --tags --abbrev=0 2>/dev/null || true)
fail=0

for f in $files; do
	[ -f "$f" ] || { echo "✗ $f: listed with --include but does not exist"; fail=1; continue; }

	now=$(version_line < "$f")
	if [ -z "$now" ]; then
		echo "✗ $f: no @version in its header (add \" * @version X.Y.Z\" under @package)"
		fail=1
		continue
	fi

	[ -n "$base" ] || continue
	git cat-file -e "$base:$f" 2>/dev/null || continue   # new since the tag: any version is fine.
	git diff --quiet "$base" -- "$f" && continue           # unchanged since the tag.

	was=$(git show "$base:$f" | version_line)
	if [ "$now" = "$was" ]; then
		echo "✗ $f: changed since $base but its @version did not (bump it to the release that ships the change)"
		fail=1
	fi
done

if [ "$fail" -ne 0 ]; then
	echo
	echo "Template versioning: a theme copy is only flagged as outdated in Site Health when @version moves."
	exit 1
fi

echo "✓ Template versioning: every overridable template is versioned and bumped where it changed${base:+ since $base}."
