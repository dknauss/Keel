#!/usr/bin/env bash
# CI-only disposable database bootstrap. Never point this at an existing install.
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  echo 'HIBP database bootstrap is restricted to GitHub Actions.' >&2
  exit 2
fi
lab_wp="${1:?Pass a new absolute WordPress directory}"
lab_version="${2:-latest}"
lab_repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ "$lab_wp" != /* || "$lab_wp" == / || -e "$lab_wp/wp-config.php" || -e "$lab_wp/wp-load.php" ]]; then
  echo 'Refusing a non-absolute path or an existing WordPress installation.' >&2
  exit 2
fi
mkdir -p "$lab_wp"
lab_wp="$(cd -- "$lab_wp" && pwd)"
wp_lab() { wp --path="$lab_wp" --allow-root "$@"; }

wp_lab core download --version="$lab_version" --locale=en_US
wp_lab config create --dbname="${KEEL_LAB_DB_NAME:-wordpress}" \
  --dbuser="${KEEL_LAB_DB_USER:-root}" --dbpass="${KEEL_LAB_DB_PASSWORD:-root}" \
  --dbhost="${KEEL_LAB_DB_HOST:-127.0.0.1:3306}"
wp_lab config set KEEL_HIBP_BUDGET_LAB true --raw
wp_lab config set DISABLE_WP_CRON true --raw
wp_lab config set AUTOMATIC_UPDATER_DISABLED true --raw
wp_lab config set WP_AUTO_UPDATE_CORE false --raw
wp_lab config set WP_REDIS_HOST 127.0.0.1
wp_lab config set WP_REDIS_PORT 6379 --raw
wp_lab config set WP_REDIS_PREFIX keel-hibp-budget-lab:
touch "$lab_wp/.hibp-budget-lab"
mkdir -p "$lab_wp/wp-content/mu-plugins"
cat > "$lab_wp/wp-content/mu-plugins/hibp-budget-network-guard.php" <<'PHP'
<?php
/** Block live HIBP calls even during bootstrap; workers supply synthetic responses. */
if ( ! defined( 'KEEL_HIBP_BUDGET_LAB' ) || true !== KEEL_HIBP_BUDGET_LAB ) {
    return;
}
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    if ( false !== $pre ) {
        return $pre;
    }
    return 'api.pwnedpasswords.com' === wp_parse_url( $url, PHP_URL_HOST )
        ? new WP_Error( 'hibp_lab_live_transport_blocked', 'Live HIBP requests are forbidden in this lab.' ) : $pre;
}, PHP_INT_MAX, 3 );
PHP

wp_lab core multisite-install --url=http://hibp-budget.test --title='HIBP budget lab' \
  --admin_user=admin --admin_password=ci-fixture-only --admin_email=admin@example.test --skip-email

# Install tooling before disabling all non-intercepted outbound WordPress HTTP.
wp_lab plugin install redis-cache --activate-network
wp_lab redis enable
wp_lab redis status
ln -s "$lab_repo" "$lab_wp/wp-content/plugins/keel"

cat > "$lab_wp/hibp-budget-bootstrap.php" <<'PHP'
<?php
if ( ! defined( 'KEEL_HIBP_BUDGET_LAB' ) || true !== KEEL_HIBP_BUDGET_LAB || ! is_multisite() ) {
    throw new RuntimeException( 'Disposable multisite lab required.' );
}
global $wpdb;
function keel_lab_site( $network, $domain, $path ) {
    if ( get_network_option( $network, 'ms_files_rewriting', true ) ) {
        throw new RuntimeException( 'Fixture network must use modern upload paths before creating sites.' );
    }
    $id = wp_insert_site( array( 'network_id' => $network, 'domain' => $domain, 'path' => $path, 'public' => 1 ) );
    if ( is_wp_error( $id ) ) {
        throw new RuntimeException( $id->get_error_message() );
    }
    if ( ! wp_is_site_initialized( $id ) ) {
        $result = wp_initialize_site( $id, array( 'user_id' => 1, 'title' => 'HIBP synthetic fixture' ) );
        if ( is_wp_error( $result ) ) {
            throw new RuntimeException( $result->get_error_message() );
        }
    }
    return (int) $id;
}
$first = get_current_network_id();
// Match modern core multisite installation defaults on every fixture network.
// Absent metadata defaults to legacy blogs.dir rewriting during site creation,
// but this modern installation intentionally has no UPLOADBLOGSDIR constant.
update_network_option( $first, 'ms_files_rewriting', 0 );
$child = keel_lab_site( $first, 'hibp-budget.test', '/child/' );
if ( ! $wpdb->insert( $wpdb->site, array( 'domain' => 'hibp-network-two.test', 'path' => '/' ), array( '%s', '%s' ) ) ) {
    throw new RuntimeException( 'Could not create second network.' );
}
$second = (int) $wpdb->insert_id;
clean_network_cache( $second );
update_network_option( $second, 'site_name', 'HIBP second network' );
update_network_option( $second, 'admin_email', 'admin@example.test' );
update_network_option( $second, 'site_admins', array( 'admin' ) );
update_network_option( $second, 'ms_files_rewriting', 0 );
$main = keel_lab_site( $second, 'hibp-network-two.test', '/' );
update_network_option( $second, 'main_site', $main );
clean_network_cache( $second );
$other_child = keel_lab_site( $second, 'hibp-network-two.test', '/child/' );
if ( get_main_site_id( $first ) === get_main_site_id( $second ) || get_main_site_id( $second ) !== $main ) {
    throw new RuntimeException( 'Networks must resolve distinct canonical main sites.' );
}
foreach ( array( get_main_site_id( $first ), $child, $main, $other_child ) as $id ) {
    $table = $wpdb->get_blog_prefix( $id ) . 'options';
    $row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
    if ( ! $row || 'innodb' !== strtolower( $row->Engine ) ) {
        throw new RuntimeException( 'Fixture options tables require InnoDB.' );
    }
}
file_put_contents( ABSPATH . 'hibp-budget-fixture.json', wp_json_encode( array(
    'networks' => array(
        array( 'id' => $first, 'main' => get_main_site_id( $first ), 'child' => $child ),
        array( 'id' => $second, 'main' => $main, 'child' => $other_child ),
    ),
) ) );
echo "Two networks and their main/child options tables are ready.\n";
PHP
wp_lab eval-file "$lab_wp/hibp-budget-bootstrap.php" --skip-plugins --skip-themes
wp_lab plugin activate keel --network
wp_lab plugin activate keel --network --url=http://hibp-network-two.test
wp_lab plugin activate redis-cache --network --url=http://hibp-network-two.test
wp_lab config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp_lab eval 'if (!wp_using_ext_object_cache()) { throw new RuntimeException("Persistent cache drop-in missing."); } if (!wp_cache_set("hibp_lab_probe", "ready", "hibp_lab", 60)) { throw new RuntimeException("Redis write failed."); }'
wp_lab eval 'if ("ready" !== wp_cache_get("hibp_lab_probe", "hibp_lab")) { throw new RuntimeException("Redis did not persist across PHP processes."); } wp_cache_delete("hibp_lab_probe", "hibp_lab");'
echo "HIBP budget CI fixture ready: WordPress $lab_version; Redis verified across processes."
