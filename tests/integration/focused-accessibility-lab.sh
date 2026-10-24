#!/usr/bin/env bash
# Run the focused accessibility regression against two disposable localhost
# installs that already have this checkout active: a single site and a multisite
# network, each with the Arabic core language installed.
#
#   bash tests/integration/focused-accessibility-lab.sh \
#     /path/to/single http://127.0.0.1:8889 /path/to/multi http://127.0.0.1:8890
#
# CI builds the installs with http-authorization-bootstrap.sh. KEEL_A11Y_ENGINES
# limits the browsers (default all three); KEEL_A11Y_OUTPUT names a new evidence
# directory. KEEL_HTTP_LAB_WP overrides the WP-CLI command (default `wp`).
#
# For each install it adds a temporary owner (an administrator, and a super admin
# on the network) and an MU wrapper that loads the fixture. Both are removed on
# exit, pass or fail. The owner's password lives only in this process tree.
set -euo pipefail

single_path="${1:?Pass the single-site WordPress path}"
single_url="${2:?Pass the single-site URL}"
multi_path="${3:?Pass the multisite WordPress path}"
multi_url="${4:?Pass the multisite URL}"
lab_repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
read -r -a wp_cmd <<<"${KEEL_HTTP_LAB_WP:-wp}"
wrapper_name='keel-focused-accessibility-lab.php'
export KEEL_A11Y_USER='keel_a11y_owner'
KEEL_A11Y_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
export KEEL_A11Y_PASSWORD

wp_lab() { local path="$1"; shift; "${wp_cmd[@]}" --path="$path" "$@"; }

wrapped=()
owners=()
cleanup() {
  local status=$? path
  for path in "${owners[@]}"; do
    wp_lab "$path" eval '
      $user = get_user_by( "login", getenv( "KEEL_A11Y_USER" ) );
      if ( $user ) {
        require_once ABSPATH . "wp-admin/includes/user.php";
        if ( is_multisite() ) {
          require_once ABSPATH . "wp-admin/includes/ms.php";
          revoke_super_admin( $user->ID );
          wpmu_delete_user( $user->ID );
        } else {
          wp_delete_user( $user->ID );
        }
      }
      if ( get_user_by( "login", getenv( "KEEL_A11Y_USER" ) ) ) { exit( 1 ); }
    ' || status=1
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
  if wp_lab "$path" user get "$KEEL_A11Y_USER" --field=ID >/dev/null 2>&1; then
    echo "The lab owner already exists in $path; clean up the earlier run first." >&2
    exit 2
  fi
  mkdir -p "$mu"
  cat >"$mu/$wrapper_name" <<PHP
<?php
// Temporary focused accessibility lab wrapper; removed by focused-accessibility-lab.sh.
require '$lab_repo/tests/integration/fixtures/focused-accessibility.php';
PHP
  wrapped+=("$path")
  owners+=("$path")
  wp_lab "$path" eval '
    $id = wp_insert_user( array(
      "user_login" => getenv( "KEEL_A11Y_USER" ),
      "user_pass"  => getenv( "KEEL_A11Y_PASSWORD" ),
      "user_email" => "a11y-owner@example.test",
      "role"       => "administrator",
    ) );
    if ( is_wp_error( $id ) ) { fwrite( STDERR, $id->get_error_message() . "\n" ); exit( 1 ); }
    if ( is_multisite() ) { grant_super_admin( $id ); }
  '
done

node "$lab_repo/tests/integration/focused-accessibility.mjs" "$single_url" "$multi_url"
