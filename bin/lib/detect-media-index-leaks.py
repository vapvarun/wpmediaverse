#!/usr/bin/env python3
"""Detect direct $wpdb table-name construction for mvs_media_index outside MediaRepository.

Shared by bin/coding-rules-check.sh (Rule 7) and bin/mutation-test-rule7.sh, so
the detector is defined once and the mutation test proves the exact same logic
the CI gate runs, not a re-implementation that could quietly drift from it.

Usage: detect-media-index-leaks.py <plugin_dir> [--allowlist PATH ...]

Prints one "relative/path.php:line" per violation, one per line. Exit code is
always 0 (the caller decides pass/fail from stdout being empty) except on a
genuine usage error.

Architecture invariant this enforces: mvs_media_index is the authoritative
media record and every read/write against it goes through
Repository\\MediaRepository (see MediaRepositoryInterface.php's "architecture
invariant 6" docblock, and plan/document-library.md §24.1/§24.2). A
predicate-checking rule doesn't catch this class of leak — only a rule that
looks at every table-name construction directly does.

DETECTION APPROACH (revised 2026-08-11): the first version of this detector
matched `$wpdb->{get_results|get_var|...}(...)` calls and checked whether
`mvs_media_index` appeared inside the call's parens. That missed the
`$index = $wpdb->prefix . 'mvs_media_index'; ... $wpdb->get_var("... {$index}
...")` pattern — the table name assigned to a variable one line before the
call, which is common enough (Pro's LeaderboardService.php does it, with a
phpcs:ignore comment showing the direct query was deliberate, not
accidental) that the call-based detector had a real false-negative blind
spot. This version instead matches the TABLE-NAME CONSTRUCTION itself —
`$wpdb->prefix . 'mvs_media_index'` or `{$wpdb->prefix}mvs_media_index` — on
any line that is not a bare comment. That is a strictly better signal: every
real query needs to construct the table name somewhere, whether inline or via
a variable, and a docblock/comment mentioning the table by name in prose
(e.g. "Purge the mvs_media_index + mvs_media_meta rows") does not perform
this construction, so it is not a false positive either.
"""
import argparse
import os
import re
import sys

DEFAULT_SCAN_DIRS = ("includes", "templates", "src")
DEFAULT_ALLOWLIST = (
    os.path.join("includes", "Repository", "MediaRepository.php"),
    os.path.join("includes", "Repository", "MediaRepositoryInterface.php"),
    # The integrity/repair queries. A SIBLING of MediaRepository rather than
    # part of it, and allowlisted for the same reason MediaRepository is: it IS
    # the repository layer. The rule's subject is "SQL against this table lives
    # in includes/Repository/", not "lives in one 4,900-line class". Its reads
    # deliberately bypass the row cache and privacy handling, because an audit
    # that reads its subject through a cache is an audit of the cache.
    os.path.join("includes", "Repository", "MediaIntegrityRepository.php"),
    os.path.join("includes", "Core", "Migrator.php"),
    os.path.join("includes", "Services", "AdminAggregatesService.php"),
)
TABLE_NAME_RE = re.compile(
    r"""\$wpdb->prefix\s*\.\s*['"]mvs_media_index['"]"""
    r"""|\{\$wpdb->prefix\}mvs_media_index"""
)
COMMENT_LINE_RE = re.compile(r"^\s*(//|#|\*)")


def find_violations(plugin_dir, scan_dirs=DEFAULT_SCAN_DIRS, allowlist=DEFAULT_ALLOWLIST):
    allowlist = set(allowlist)
    violations = []
    for scan_dir in scan_dirs:
        root = os.path.join(plugin_dir, scan_dir)
        if not os.path.isdir(root):
            continue
        for dirpath, _dirnames, filenames in os.walk(root):
            for name in filenames:
                if not name.endswith(".php"):
                    continue
                path = os.path.join(dirpath, name)
                rel = os.path.relpath(path, plugin_dir)
                if rel in allowlist:
                    continue
                try:
                    lines = open(path, encoding="utf-8").readlines()
                except (OSError, UnicodeDecodeError):
                    continue
                for line_no, line in enumerate(lines, start=1):
                    if COMMENT_LINE_RE.match(line):
                        continue
                    if TABLE_NAME_RE.search(line):
                        violations.append(f"{rel}:{line_no}")
    return violations


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("plugin_dir")
    parser.add_argument(
        "--allowlist",
        action="append",
        default=None,
        help="Relative path (repeatable) to allow instead of the built-in default list.",
    )
    args = parser.parse_args()
    allowlist = args.allowlist if args.allowlist is not None else DEFAULT_ALLOWLIST
    for line in find_violations(args.plugin_dir, allowlist=allowlist):
        print(line)
