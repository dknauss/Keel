#!/usr/bin/env bash
# Run the HTTP authorization lab against two disposable localhost installs that
# already have this checkout active: a single site and a multisite network.
#
#   bash tests/integration/http-authorization-lab.sh \
#     /path/to/single http://127.0.0.1:8889 /path/to/multi http://127.0.0.1:8890 /path/to/new-results.json
#
# Works on any runner with WP-CLI, Node and Playwright: CI builds its installs
# with http-authorization-bootstrap.sh, and Studio, wp-env or a hand-built site
# work the same way. KEEL_HTTP_LAB_WP overrides the WP-CLI command (default `wp`).
#
# For each install it adds a temporary MU wrapper that enables the fixture, runs
# setup, and always runs cleanup and removes the wrapper on exit, pass or fail.
set -euo pipefail

single_path="${1:?Pass the single-site WordPress path}"
single_url="${2:?Pass the single-site URL}"
multi_path="${3:?Pass the multisite WordPress path}"
multi_url="${4:?Pass the multisite URL}"
output="${5:?Pass a new output JSON path}"
lab_repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
read -r -a wp_cmd <<<"${KEEL_HTTP_LAB_WP:-wp}"
wrapper_name='keel-http-authorization-lab.php'

if [[ -e "$output" ]]; then
  echo "Refusing to overwrite existing evidence: $output" >&2
  exit 2
fi
# One password per run, shared by setup (which creates the owner) and the
# harness (which signs in with it). It is never written to disk.
if [[ -z "${KEEL_HTTP_LAB_PASSWORD:-}" ]]; then
  KEEL_HTTP_LAB_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
fi
export KEEL_HTTP_LAB_PASSWORD

wp_lab() { local path="$1"; shift; "${wp_cmd[@]}" --path="$path" "$@"; }

wrapped=()
prepared=()
cleanup() {
  local status=$? path
  for path in "${prepared[@]}"; do
    wp_lab "$path" eval-file "$lab_repo/tests/integration/http-authorization-setup.php" cleanup || status=1
  done
  for path in "${wrapped[@]}"; do
    rm -f "$path/wp-content/mu-plugins/$wrapper_name"
  done
  exit "$status"
}
trap cleanup EXIT

for path in "$single_path" "$multi_path"; do
  mu="$path/wp-content/mu-plugins"
  if [[ -e "$mu/$wrapper_name" ]]; then
    echo "A lab wrapper already exists in $mu; clean up the earlier run first." >&2
    exit 2
  fi
  mkdir -p "$mu"
  cat >"$mu/$wrapper_name" <<PHP
<?php
// Temporary: removed by http-authorization-lab.sh when the run ends.
define( 'KEEL_HTTP_AUTH_LAB', true );
require '$lab_repo/tests/integration/fixtures/http-authorization.php';
PHP
  wrapped+=("$path")
  # Registered before setup runs, so a setup that fails partway is still undone.
  prepared+=("$path")
  wp_lab "$path" eval-file "$lab_repo/tests/integration/http-authorization-setup.php"
done

node "$lab_repo/tests/integration/http-authorization.mjs" "$single_url" "$multi_url" "$output"
