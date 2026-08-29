#!/usr/bin/env bash
#
# Parse every PHP file in the tree on whichever PHP is on PATH.
#
# Extracted because ci.yml had two copies — one in `test`, one per version in
# the `compat` matrix — and both shared the same flaw. They ended in
# `> /dev/null`, which is there for a good reason: `php -l` prints "No syntax
# errors detected in ..." for every file it reads, and on a tree this size that
# is hundreds of lines of noise around the one line that matters. But php -l
# writes its parse errors to stdout too, so discarding stdout discarded those
# as well. A syntax error failed the job with an empty log and left you to find
# the file yourself.
#
# So: run each file, keep quiet when it parses, print the error when it does
# not, and carry on to the end rather than stopping at the first. A run that
# fails should tell you every file to fix, not the alphabetically first one.
#
# vendor/ and node_modules/ are somebody else's code and not ours to parse;
# build/ is generated and is a copy of files already checked in place.
#
# Usage: bash bin/lint-syntax.sh
set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd -P )"
cd "$ROOT"

failed=0
count=0

while IFS= read -r -d '' file; do
	count=$(( count + 1 ))
	if ! output="$( php -l "$file" 2>&1 )"; then
		printf '%s\n' "$output"
		failed=$(( failed + 1 ))
	fi
done < <(
	find . -name '*.php' \
		-not -path './vendor/*' \
		-not -path './node_modules/*' \
		-not -path './build/*' \
		-print0
)

# A run that parsed nothing is not a run that found nothing. If the find above
# ever stops matching — a moved directory, a bad exclude — this says so instead
# of reporting a clean tree it never looked at.
if [ "$count" -eq 0 ]; then
	echo "lint-syntax: no PHP files found; the search is wrong, not the tree." >&2
	exit 1
fi

if [ "$failed" -ne 0 ]; then
	echo "lint-syntax: $failed of $count file(s) failed to parse on PHP $( php -r 'echo PHP_VERSION;' )." >&2
	exit 1
fi

echo "lint-syntax: $count files parse on PHP $( php -r 'echo PHP_VERSION;' )."
